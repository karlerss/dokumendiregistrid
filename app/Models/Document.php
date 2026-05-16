<?php

namespace App\Models;

use App\Lib\Fetcher\AdrFetcher;
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
    ];

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
        $this->deleteFiles();

        $f = (new AdrFetcher($this->organisation));
        [$data, $links, $relations] = $f->getPageData($this->url);
        $newFiles = $f->downloadFiles($this->original_id, $links);
        $this->files()->sync(Arr::pluck($newFiles, 'id'));
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
