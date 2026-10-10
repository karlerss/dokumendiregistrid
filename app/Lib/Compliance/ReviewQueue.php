<?php

namespace App\Lib\Compliance;

use App\Models\ComplianceAction;
use App\Models\Document;
use App\Models\ReviewItem;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point through which every source (letters entered over
 * MCP; later the re-check daemon, takedowns and PII assessments) puts a
 * document in front of a reviewer. Flagging never changes what is published.
 */
class ReviewQueue
{
    /**
     * Attach a flag to the document's open item, creating it if needed.
     *
     * - A removed document is never flagged again; the existing decided item
     *   is returned untouched.
     * - A flag on a document whose last decision was "keep" reopens that item
     *   (the keep stays in compliance_actions).
     * - Re-flagging with the same source, reference and note is a no-op, so
     *   entering the same letter twice does not pile up duplicates.
     *
     * @param array<string, mixed> $refs ids linking back to the source row
     *                                   (status_change_id, takedown_id, ...)
     */
    public function flag(
        ?Document $document,
        ?string $fileKey,
        string $source,
        int $priority,
        ?string $reference = null,
        ?string $note = null,
        array $refs = [],
    ): ReviewItem {
        if ($document === null && $fileKey === null) {
            throw new \InvalidArgumentException('A flag needs a document or a file key.');
        }

        return DB::transaction(function () use ($document, $fileKey, $source, $priority, $reference, $note, $refs) {
            $item = $this->latestItem($document, $fileKey);

            if ($item && $document?->isRemoved()) {
                return $item;
            }

            $flag = array_filter([
                'source' => $source,
                'priority' => $priority,
                'reference' => $reference,
                'note' => $note,
                'file_key' => $document ? $fileKey : null,
                'flagged_at' => now()->toIso8601String(),
            ] + $refs, fn($v) => $v !== null);

            if ($item === null || ($item->status === ReviewItem::STATUS_DECIDED && $item->decision !== ReviewItem::DECISION_KEEP)) {
                return ReviewItem::create([
                    'document_id' => $document?->id,
                    'file_key' => $document ? null : $fileKey,
                    'status' => ReviewItem::STATUS_OPEN,
                    'priority' => $priority,
                    'flags' => [$flag],
                    'first_flagged_at' => now(),
                ]);
            }

            if ($this->hasFlag($item, $flag)) {
                return $item;
            }

            if ($item->status === ReviewItem::STATUS_DECIDED) {
                ComplianceAction::create([
                    'review_item_id' => $item->id,
                    'document_id' => $item->document_id,
                    'file_key' => $item->file_key,
                    'effect' => 'reopened',
                    'actor' => 'queue',
                    'result' => ['previous_decision' => $item->decision, 'by_source' => $source, 'reference' => $reference],
                ]);
                $item->fill([
                    'status' => ReviewItem::STATUS_OPEN,
                    'decision' => null,
                    'decision_reason' => null,
                    'decision_reference' => null,
                    'decided_by' => null,
                    'decided_at' => null,
                ]);
            }

            $item->flags = array_merge($item->flags ?? [], [$flag]);
            $item->priority = min($item->priority, $priority);
            $item->save();

            return $item;
        });
    }

    private function latestItem(?Document $document, ?string $fileKey): ?ReviewItem
    {
        $query = ReviewItem::query()->latest('id');
        $document
            ? $query->where('document_id', $document->id)
            : $query->whereNull('document_id')->where('file_key', $fileKey);

        return $query->first();
    }

    private function hasFlag(ReviewItem $item, array $flag): bool
    {
        foreach ($item->flags ?? [] as $existing) {
            if (($existing['source'] ?? null) === $flag['source']
                && ($existing['reference'] ?? null) === ($flag['reference'] ?? null)
                && ($existing['note'] ?? null) === ($flag['note'] ?? null)
                && ($existing['file_key'] ?? null) === ($flag['file_key'] ?? null)) {
                return true;
            }
        }
        return false;
    }
}
