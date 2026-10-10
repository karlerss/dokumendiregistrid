<?php

namespace App\Lib\Compliance;

use App\Models\PiiRedaction;
use App\Models\TakedownRequest;
use App\Models\PiiAssessment;
use App\Models\DocumentStatusChange;
use App\Models\DocumentRemoteState;
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

        return $this->writeTransaction(function () use ($document, $fileKey, $source, $priority, $reference, $note, $refs) {
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

    /**
     * Run a read-then-write as one SQLite write transaction that takes the
     * write lock up front (BEGIN IMMEDIATE). The daemon and queue worker
     * write constantly; a default (deferred) transaction that has already
     * read gets "database is locked" the moment another writer commits,
     * and the busy timeout does not help. IMMEDIATE waits its turn under
     * the busy timeout instead. Inside an outer transaction (tests, callers
     * that already hold one) Laravel's nesting is used as usual.
     */
    private function writeTransaction(\Closure $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->transactionLevel() > 0) {
            return $connection->transaction($callback, 3);
        }

        $pdo = $connection->getPdo();
        for ($attempt = 1; ; $attempt++) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
            } catch (\PDOException $e) {
                if ($attempt < 5 && str_contains($e->getMessage(), 'database is locked')) {
                    usleep(200_000 * $attempt);
                    continue;
                }
                throw $e;
            }
            try {
                $result = $callback();
                $pdo->exec('COMMIT');
                return $result;
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }
    }

    /**
     * A redaction whose text step failed changed nothing, so the "redact"
     * decision did not take effect: reopen the item for a new decision and
     * log why (the planned strings still found, or the error).
     */
    public function reopenAfterFailedRedaction(PiiRedaction $redaction): ?ReviewItem
    {
        $item = ReviewItem::query()
            ->where('document_id', $redaction->document_id)
            ->where('status', ReviewItem::STATUS_DECIDED)
            ->where('decision', ReviewItem::DECISION_REDACT)
            ->latest('id')
            ->first();
        if (!$item) {
            return null;
        }

        $failure = collect($redaction->log ?? [])->last(fn($e) => ($e['step'] ?? null) === 'text' && ($e['outcome'] ?? null) === 'failed') ?? [];
        return $this->writeTransaction(function () use ($item, $redaction, $failure) {
            ComplianceAction::create([
                'review_item_id' => $item->id,
                'document_id' => $item->document_id,
                'effect' => 'redaction_failed',
                'actor' => 'queue',
                'result' => array_filter([
                    'redaction_id' => $redaction->id,
                    'leftover' => array_column($failure['leftover'] ?? [], 'surface') ?: null,
                    'error' => $failure['error'] ?? null,
                ]),
            ]);
            ComplianceAction::create([
                'review_item_id' => $item->id,
                'document_id' => $item->document_id,
                'effect' => 'reopened',
                'actor' => 'queue',
                'result' => ['previous_decision' => ReviewItem::DECISION_REDACT, 'why' => "redigeerimine #{$redaction->id} ebaõnnestus; midagi ei muudetud"],
            ]);
            $item->forceFill([
                'status' => ReviewItem::STATUS_OPEN,
                'decision' => null,
                'decision_reason' => null,
                'decision_reference' => null,
                'decided_by' => null,
                'decided_at' => null,
            ])->save();
            return $item;
        });
    }

    // ---------------------------------------------------- per-source flags

    /** A source-registry restriction or removal found by the re-check daemon. */
    public function flagStatusChange(DocumentStatusChange $change): ?ReviewItem
    {
        $document = $change->document;
        if (!$document || $change->to_status === DocumentRemoteState::STATUS_PUBLIC) {
            return null; // a document becoming public again needs no data-protection review
        }
        return $this->flag($document, null, ReviewItem::SOURCE_RECHECK, self::priorityForChange($change), null,
            $change->transitionLabel(), ['status_change_id' => $change->id, 'personal_data' => (bool)$change->personal_data]);
    }

    /** A takedown request whose sender confirmed their e-mail address. */
    public function flagTakedown(TakedownRequest $takedown): ?ReviewItem
    {
        if (!$takedown->document) {
            return null;
        }
        return $this->flag($takedown->document, null, ReviewItem::SOURCE_TAKEDOWN, ReviewItem::PRIORITY_NAMED,
            "eemaldamistaotlus #{$takedown->id}", $takedown->legal_basis, $takedown->reviewFacts());
    }

    /** An unreviewed PII assessment in the HIGH band. */
    public function flagAssessment(PiiAssessment $assessment): ?ReviewItem
    {
        if ($assessment->band !== PiiAssessment::BAND_HIGH || $assessment->isReviewed() || !$assessment->document) {
            return null;
        }
        return $this->flag($assessment->document, null, ReviewItem::SOURCE_PII, ReviewItem::PRIORITY_PII, null,
            $assessment->recommendationLabel(), ['assessment_id' => $assessment->id, 'personal_data' => true]);
    }

    /** A document hidden earlier whose files are still publicly downloadable. */
    public function flagHiddenWithPublicFiles(Document $document): ?ReviewItem
    {
        return $this->flag($document, null, ReviewItem::SOURCE_HIDDEN, ReviewItem::PRIORITY_NAMED, null,
            'peidetud, kuid failid on avalikult alla laaditavad');
    }

    public static function priorityForChange(DocumentStatusChange $change): int
    {
        return match (true) {
            (bool)$change->personal_data => ReviewItem::PRIORITY_NAMED,
            $change->to_status === DocumentRemoteState::STATUS_RESTRICTED => ReviewItem::PRIORITY_RESTRICTED,
            default => ReviewItem::PRIORITY_GONE,
        };
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
