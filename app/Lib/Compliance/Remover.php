<?php

namespace App\Lib\Compliance;

use App\Lib\CdnPurge;
use App\Models\ComplianceAction;
use App\Models\Document;
use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\PiiAssessment;
use App\Models\PiiExtraction;
use App\Models\PiiRedaction;
use App\Models\PiiSubject;
use App\Models\ReviewItem;
use Illuminate\Support\Facades\Storage;

/**
 * Carries out a "remove" decision: stops both publishing and storing the
 * document's content. Only called from a reviewed decision (Decider); never
 * from a daemon or batch.
 *
 * Every step is idempotent, so a removal interrupted by an R2 error can be
 * retried by deciding the (still open) item again.
 */
class Remover
{
    /**
     * What removing the document would touch, without touching it.
     */
    public function planDocument(Document $document): array
    {
        $files = File::query()->where('document_id', $document->id)->orderBy('id')->get();

        return [
            'document_id' => $document->id,
            'files' => $files->map(fn(File $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'objects' => $this->objectsOf($f),
            ])->all(),
            'clears' => array_values(array_filter([
                'file_contents', 'ai_title', 'ai_summary',
                config('compliance.tombstone_keep_text') ? null : 'title',
                config('compliance.tombstone_keep_text') ? null : 'to',
                config('compliance.tombstone_keep_text') ? null : 'responsible',
            ])),
            'pii_rows' => [
                'extractions' => PiiExtraction::where('document_id', $document->id)->count(),
                'subjects' => PiiSubject::where('document_id', $document->id)->count(),
                'assessments' => PiiAssessment::where('document_id', $document->id)->count(),
                'redactions' => PiiRedaction::where('document_id', $document->id)->count(),
            ],
            'acknowledges_status_changes' => $this->openStatusChanges($document)->pluck('id')->all(),
            'kept_on_row' => array_values(array_filter([
                'organisation_id', 'original_id', 'url', 'reference', 'registration_date', 'type',
                config('compliance.tombstone_keep_text') ? 'title' : null,
                config('compliance.tombstone_keep_text') ? 'to' : null,
                config('compliance.tombstone_keep_text') ? 'responsible' : null,
            ])),
            'page_after' => 410,
            'irreversible' => true,
        ];
    }

    public function removeDocument(Document $document, ReviewItem $item, string $actor): void
    {
        $log = fn(string $effect, array $result = []) => ComplianceAction::create([
            'review_item_id' => $item->id,
            'document_id' => $document->id,
            'effect' => $effect,
            'actor' => $actor,
            'result' => $result,
        ]);

        // Root files delete their children, signatures and every R2 copy.
        $files = File::query()->where('document_id', $document->id)->whereNull('parent_id')->get();
        foreach ($files as $file) {
            $objects = $this->objectsOf($file, recursive: true);
            $file->delete();
            $log('file_deleted', ['file_id' => $file->id, 'name' => $file->name, 'objects' => $objects]);
        }
        // Anything orphaned by an earlier partial delete.
        foreach (File::query()->where('document_id', $document->id)->get() as $file) {
            $objects = $this->objectsOf($file);
            $file->delete();
            $log('file_deleted', ['file_id' => $file->id, 'name' => $file->name, 'objects' => $objects]);
        }

        // File::delete() purges each key it deletes; purging the full set
        // again here is idempotent and puts the outcome in the audit log.
        $public = [];
        foreach (ComplianceAction::where('review_item_id', $item->id)->where('effect', 'file_deleted')->get() as $action) {
            foreach ($action->result['objects'] ?? [] as $object) {
                if (str_starts_with($object, 'r2:')) {
                    $public[] = substr($object, 3);
                }
            }
        }
        if ($public) {
            $log('cdn_purged', app(CdnPurge::class)->keys($public));
        }

        // PII rows hold names and the unredacted strings: storage of the very
        // data being removed.
        $pii = [
            'redactions' => PiiRedaction::where('document_id', $document->id)->delete(),
            'assessments' => PiiAssessment::where('document_id', $document->id)->delete(),
            'subjects' => PiiSubject::where('document_id', $document->id)->delete(),
            'extractions' => PiiExtraction::where('document_id', $document->id)->delete(),
        ];
        if (array_sum($pii) > 0) {
            $log('pii_rows_deleted', $pii);
        }

        $document->ftsIndexSingle();
        $fields = [
            'file_contents' => null,
            'ai_title' => null,
            'ai_summary' => null,
            'redacted_at' => null,
            'visible' => false,
            'removed_at' => now(),
        ];
        if (!config('compliance.tombstone_keep_text')) {
            $fields += [
                'title' => config('compliance.tombstone_title'),
                'to' => null,
                'responsible' => null,
            ];
        }
        $document->forceFill($fields)->save();
        $log('document_cleared', ['fields' => array_keys($fields)]);

        $changes = $this->openStatusChanges($document)->pluck('id')->all();
        if ($changes) {
            DocumentStatusChange::whereIn('id', $changes)->update([
                'acknowledged_at' => now(),
                'action' => DocumentStatusChange::ACTION_REMOVED,
                'note' => "review item #{$item->id}",
            ]);
            $log('status_changes_acknowledged', ['ids' => $changes]);
        }
    }

    /**
     * Remove an R2 object that no files row points at (left behind by old
     * code or manual cleanup) but that is still publicly reachable.
     */
    public function removeOrphanObject(string $key, ReviewItem $item, string $actor): void
    {
        Storage::disk('r2')->delete($key);
        ComplianceAction::create([
            'review_item_id' => $item->id,
            'file_key' => $key,
            'effect' => 'orphan_object_deleted',
            'actor' => $actor,
            'result' => ['disk' => 'r2', 'key' => $key, 'cdn' => app(CdnPurge::class)->keys([$key])],
        ]);
    }

    /** @return string[] "disk:key" for every stored copy of the file */
    private function objectsOf(File $file, bool $recursive = false): array
    {
        $objects = array_values(array_filter([
            $file->original_private_location ? 'r2_private:' . $file->original_private_location : null,
            !$file->original_private_location && $file->location ? 'r2:' . $file->location : null,
            $file->redacted_location ? 'r2:' . $file->redacted_location : null,
        ]));
        if ($recursive) {
            foreach ($file->children as $child) {
                $objects = array_merge($objects, $this->objectsOf($child, true));
            }
        }
        return $objects;
    }

    private function openStatusChanges(Document $document)
    {
        return DocumentStatusChange::query()
            ->where('document_id', $document->id)
            ->whereNull('acknowledged_at');
    }
}
