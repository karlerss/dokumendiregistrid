<?php

namespace App\Lib\Compliance;

use App\Models\Document;
use App\Models\PiiRedaction;
use App\Models\ReviewItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch decision from the "Redigeerimise vead" card: remove every document
 * whose current redaction failed (text step failed, or files failed or only
 * partly done). Each document gets its own recorded "remove" decision.
 */
class FailedRedactionRemoval
{
    public const DEFAULT_REASON = 'Isikuandmete osaline eemaldamine ebaõnnestus. Failid eemaldatud.';

    public function __construct(private AdminActions $admin)
    {
    }

    /** The latest live redaction of each document still on the site, where that redaction failed. */
    public static function query(): Builder
    {
        return PiiRedaction::query()
            ->whereNull('reverted_at')
            ->whereIn('id', PiiRedaction::query()->whereNull('reverted_at')->selectRaw('max(id)')->groupBy('document_id'))
            ->where(fn($q) => $q->where('text_status', PiiRedaction::STATUS_FAILED)
                ->orWhereIn('files_status', [PiiRedaction::STATUS_FAILED, PiiRedaction::STATUS_PARTIAL]))
            ->whereHas('document', fn($q) => $q->whereNull('removed_at'));
    }

    /** @return array{removed: int[], failed: array<int, string>} */
    public function run(string $reason, ?string $reference = null, string $actor = BatchRedaction::ACTOR): array
    {
        $removed = [];
        $failed = [];
        // IDs first, documents one by one: no open cursor while deciding
        // (see BatchRedaction).
        foreach (self::query()->orderBy('document_id')->pluck('id', 'document_id') as $documentId => $redactionId) {
            $document = Document::find($documentId);
            if (!$document || $document->isRemoved()) {
                continue;
            }
            try {
                $this->admin->decide(
                    $document,
                    ReviewItem::DECISION_REMOVE,
                    $reason,
                    ReviewItem::SOURCE_PII,
                    ReviewItem::PRIORITY_PII,
                    $reference,
                    'redigeerimine ebaõnnestus',
                    ['redaction_id' => $redactionId, 'personal_data' => true],
                    [],
                    $actor,
                );
                $removed[] = $documentId;
            } catch (\Throwable $e) {
                $failed[$documentId] = $e->getMessage();
            }
        }
        return ['removed' => $removed, 'failed' => $failed];
    }
}
