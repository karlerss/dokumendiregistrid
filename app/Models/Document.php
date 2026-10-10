<?php

namespace App\Models;

use App\Lib\Fetcher\AdrFetcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class Document extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'registration_date' => 'date',
        'visible' => 'boolean',
        'redacted_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A removal is irreversible by design: the files are gone, and the
        // row is the tombstone that keeps fetchers from re-ingesting the
        // document. Guard every path (hide/unhide buttons, full delete).
        static::saving(function (Document $document) {
            if ($document->getOriginal('removed_at') !== null && $document->visible) {
                throw new \RuntimeException("Document {$document->id} was removed by a compliance decision and cannot be shown again.");
            }
        });
        static::deleting(function (Document $document) {
            if ($document->removed_at !== null) {
                throw new \RuntimeException("Document {$document->id} was removed by a compliance decision; its row must stay to block re-ingestion.");
            }
        });
    }

    /**
     * Documents that may be shown to non-admin visitors.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visible', true);
    }

    /**
     * Subquery of hidden/removed document ids, for excluding them from the
     * large listing queries without losing their index plans.
     */
    public static function hiddenIds(): \Closure
    {
        return fn($q) => $q->select('id')->from('documents')->where('visible', false);
    }

    /**
     * Documents the re-check daemon is responsible for: public at ingest and
     * not removed (a removed document has nothing left to re-check).
     */
    public function scopeRecheckable(Builder $query): Builder
    {
        return $query->where('restriction', 'Avalik')->whereNull('documents.removed_at');
    }

    /**
     * Hot re-check state, kept in its own table so the daemon's frequent
     * writes do not fire the FTS triggers on `documents`.
     */
    public function remoteState()
    {
        return $this->hasOne(DocumentRemoteState::class);
    }

    public function statusChanges()
    {
        return $this->hasMany(DocumentStatusChange::class)->orderByDesc('occurred_at');
    }

    public function hasOpenTakedownRequest(): bool
    {
        return $this->takedownRequests()
            ->whereIn('status', [TakedownRequest::STATUS_PENDING, TakedownRequest::STATUS_ACCEPTED])
            ->exists();
    }

    public function files()
    {
        return $this->hasMany(File::class);
    }

    public function restrictions()
    {
        return $this->hasMany(RestrictionBasis::class);
    }

    public function rootFiles()
    {
        return $this->files()->whereNull('parent_id');
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function getContentsForSummary()
    {
        return $this->files->unique('name')->map(function (File $file) {
            $contents = strip_tags($file->contents);
            return 'Original filename: ' . $file->name . "\n\n" . $contents;
        })->implode("\n\n\n\n");
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function takedownRequests()
    {
        return $this->hasMany(TakedownRequest::class);
    }

    public function piiExtractions()
    {
        return $this->hasMany(PiiExtraction::class);
    }

    /** Most recent extraction at the current prompt version, any status. */
    public function latestPiiExtraction()
    {
        return $this->hasOne(PiiExtraction::class)->ofMany(['id' => 'max'], fn($q) => $q->where('prompt_version', \App\Lib\Pii\Extractor::PROMPT_VERSION));
    }

    public function piiAssessments()
    {
        return $this->hasMany(PiiAssessment::class);
    }

    /** Most recent assessment (any rules version). */
    public function latestPiiAssessment()
    {
        return $this->hasOne(PiiAssessment::class)->latestOfMany();
    }

    public function piiRedactions()
    {
        return $this->hasMany(PiiRedaction::class);
    }

    /** The redaction currently in effect, if any. */
    public function activePiiRedaction(): ?PiiRedaction
    {
        return $this->piiRedactions()->whereNull('reverted_at')->where('text_status', PiiRedaction::STATUS_APPLIED)->latest('id')->first();
    }

    /**
     * Removed through a compliance decision: files and text are gone, only
     * an identifying row remains so the fetchers do not re-ingest it.
     */
    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function reviewItems()
    {
        return $this->hasMany(ReviewItem::class);
    }

    public function isRedacted(): bool
    {
        return $this->redacted_at !== null;
    }

    public function fullDelete()
    {
        $this->deleteFiles();
        $this->delete();
    }

    public function deleteFiles()
    {
        $files = $this->files;
        foreach ($files as $file) {
            $file->delete();
        }
    }

    public function reloadMetadata()
    {
        $f = (new AdrFetcher($this->organisation));
        [$data, $links, $relations] = $f->getPageData($this->url);
        $this->restrictions()->delete();
        [$props, $restrictions] = $f->getDocPropsFromData($this->original_id, $data);
        $this->update($props);
        foreach ($restrictions as $restriction) {
            $this->restrictions()->create(['basis' => $restriction]);
        }
        $this->touch();
    }

    public function destroyWithFiles()
    {
        $this->deleteFiles();

        $this->delete();
    }

    public function reindex()
    {
        if ($this->isRemoved()) {
            throw new \RuntimeException("Document {$this->id} was removed by a compliance decision and cannot be re-fetched.");
        }

        $this->deleteFiles();

        $f = (new AdrFetcher($this->organisation));
        [$data, $links, $relations] = $f->getPageData($this->url);
        $newFiles = $f->downloadFiles($this->original_id, $links);
        $this->files()->saveMany($newFiles);
        $this->touch();
        $this->ftsIndexSingle();
    }

    public function ftsIndexSingle()
    {
        DB::unprepared(<<<SQLITE
        update documents
        set file_contents = (
            select group_concat(f.name || ' ' || f.contents, '  ') as file_contents
            from files f
            where f.document_id = documents.id
        )
        where id = $this->id;
        SQLITE
        );

    }

    public static function ftsIndexAll()
    {
        DB::unprepared(<<<SQLITE
        insert into fts_documents (rowid, title, responsible, series, "to", function, original_id, reference, file_contents)
        select documents.id,
               title,
               responsible,
               series,
               "to",
               function,
               original_id,
               reference,
               group_concat(f.name || ' ' || f.contents, '  ') as file_contents
        from documents
                 left join files f on f.document_id = documents.id
        group by documents.id;
        ANALYZE;
        SQLITE
        );

    }
}
