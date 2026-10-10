<?php

namespace App\Lib\Compliance;

use App\Models\ComplianceAction;
use App\Models\Document;
use App\Models\ReviewItem;

/**
 * The /haldus side of the compliance record. Buttons that amount to a
 * decision (remove, redact, keep) go through the same Decider as the MCP,
 * on the document's review item; every other button that changes what is
 * published or stored is logged on that item. Together the report can show
 * everything that was done to a document, wherever it was done.
 */
class AdminActions
{
    public const ACTOR = 'haldus';

    public function __construct(private ReviewQueue $queue, private Decider $decider)
    {
    }

    /**
     * Flag the document from the button's source (joining its open item if
     * there is one) and decide it.
     *
     * @param array<string, mixed> $refs links back to the source row and the
     *                                   public_sector / personal_data facts
     * @throws DecisionException
     */
    public function decide(
        Document $document,
        string $decision,
        string $reason,
        string $source,
        int $priority,
        ?string $reference,
        string $note,
        array $refs = [],
        array $options = [],
    ): ReviewItem {
        $item = $this->queue->flag($document, null, $source, $priority, $reference, $note, $refs);
        // Deciding again in /haldus overrides an earlier "keep" (removal and
        // live redactions stay final until reverted).
        if (!$item->isOpen() && $item->decision === ReviewItem::DECISION_KEEP) {
            $this->reopen($document, ReviewItem::DECISION_KEEP, 'uus otsus /haldus kaudu');
            $item->refresh();
        }
        return $this->decider->execute($item->fresh(['document']), $decision, $reason, $reference, self::ACTOR, $options);
    }

    /** Record a /haldus action that is not itself a decision. */
    public function log(?Document $document, string $effect, array $result = []): ComplianceAction
    {
        return ComplianceAction::create([
            'review_item_id' => $document ? ReviewItem::where('document_id', $document->id)->latest('id')->value('id') : null,
            'document_id' => $document?->id,
            'effect' => $effect,
            'actor' => self::ACTOR,
            'result' => $result,
        ]);
    }

    /**
     * Undo the "decided" state when the decision itself was undone (a
     * redaction reverted): the document needs a new decision.
     */
    public function reopen(Document $document, string $decision, string $why): void
    {
        $item = ReviewItem::where('document_id', $document->id)->where('decision', $decision)->latest('id')->first();
        if (!$item) {
            return;
        }
        $item->forceFill([
            'status' => ReviewItem::STATUS_OPEN,
            'decision' => null,
            'decision_reason' => null,
            'decision_reference' => null,
            'decided_by' => null,
            'decided_at' => null,
        ])->save();
        ComplianceAction::create([
            'review_item_id' => $item->id,
            'document_id' => $document->id,
            'effect' => 'reopened',
            'actor' => self::ACTOR,
            'result' => ['previous_decision' => $decision, 'why' => $why],
        ]);
    }
}
