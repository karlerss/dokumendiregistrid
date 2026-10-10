<?php

namespace App\Lib\Compliance;

use App\Models\ComplianceAction;
use App\Models\DocumentStatusChange;
use App\Jobs\RedactDocument;
use App\Lib\Pii\Assessor;
use App\Lib\Pii\Grounding;
use App\Lib\Pii\OriginalText;
use App\Lib\Pii\RedactionPlanner;
use App\Models\PiiAssessment;
use App\Models\PiiRedaction;
use App\Models\ReviewItem;

/**
 * Closes a review item with one of the three decisions. The only code path
 * that changes what is published in response to a flag; shared by the MCP
 * server and (later) the /haldus review page.
 *
 * "remove" is irreversible, so it runs in two steps: plan() describes exactly
 * what would be touched and returns a plan_id; decide() executes only when
 * given the plan_id of the current state of the item.
 */
class Decider
{
    public function __construct(private Remover $remover)
    {
    }

    /**
     * @param array{strings?: string[]} $options redact only: strings to
     *        remove instead of the PII assessment's plan
     * @return array{plan_id: string, decision: string, effects: array}
     */
    public function plan(ReviewItem $item, string $decision, array $options = []): array
    {
        $this->assertDecidable($item, $decision);

        $effects = match ($decision) {
            ReviewItem::DECISION_REDACT => $this->redactionEffects($item, $options),
            ReviewItem::DECISION_REMOVE => $item->document_id
                ? $this->remover->planDocument($item->document)
                : ['file_key' => $item->file_key, 'objects' => ['r2:' . $item->file_key], 'irreversible' => true],
            ReviewItem::DECISION_KEEP => [
                'public_change' => 'none',
                'acknowledges_status_changes' => $item->document_id
                    ? DocumentStatusChange::where('document_id', $item->document_id)->whereNull('acknowledged_at')->pluck('id')->all()
                    : [],
            ],
        };

        return [
            'plan_id' => $this->planId($item, $decision, $effects + ['options' => $options]),
            'decision' => $decision,
            'effects' => $effects,
        ];
    }

    /**
     * @param string|null $planId required for "remove"; must match plan() for
     *                            the item as it is now
     */
    public function decide(
        ReviewItem $item,
        string $decision,
        string $reason,
        ?string $reference,
        string $actor,
        ?string $planId = null,
        array $options = [],
    ): ReviewItem {
        $plan = $this->plan($item, $decision, $options);

        if ($decision !== ReviewItem::DECISION_KEEP && $planId !== $plan['plan_id']) {
            throw new DecisionException($planId === null
                ? 'Run the decision without confirm first and pass the returned plan_id.'
                : 'The item changed since that plan was made; review the new plan and confirm its plan_id.');
        }

        return $this->run($item, $decision, $reason, $reference, $actor, $plan);
    }

    /**
     * Carry out a decision confirmed in the /haldus UI, where the button's
     * confirmation dialog takes the place of the dry run.
     */
    public function execute(
        ReviewItem $item,
        string $decision,
        string $reason,
        ?string $reference,
        string $actor,
        array $options = [],
    ): ReviewItem {
        return $this->run($item, $decision, $reason, $reference, $actor, $this->plan($item, $decision, $options));
    }

    private function run(ReviewItem $item, string $decision, string $reason, ?string $reference, string $actor, array $plan): ReviewItem
    {
        if (trim($reason) === '') {
            throw new DecisionException('A reason is required for every decision.');
        }

        if ($decision === ReviewItem::DECISION_REMOVE) {
            $item->document_id
                ? $this->remover->removeDocument($item->document, $item, $actor)
                : $this->remover->removeOrphanObject($item->file_key, $item, $actor);
        } elseif ($decision === ReviewItem::DECISION_REDACT) {
            $redaction = $this->startRedaction($item, $plan['effects'], $actor);
        } else {
            $ids = $plan['effects']['acknowledges_status_changes'];
            if ($ids) {
                DocumentStatusChange::whereIn('id', $ids)->update([
                    'acknowledged_at' => now(),
                    'action' => DocumentStatusChange::ACTION_KEPT,
                    'note' => "review item #{$item->id}",
                ]);
            }
        }

        ComplianceAction::create([
            'review_item_id' => $item->id,
            'document_id' => $item->document_id,
            'file_key' => $item->file_key,
            'effect' => 'decided_' . $decision,
            'actor' => $actor,
            'result' => ['reason' => $reason, 'reference' => $reference, 'plan_id' => $plan['plan_id']],
        ]);

        $item->forceFill([
            'status' => ReviewItem::STATUS_DECIDED,
            'decision' => $decision,
            'decision_reason' => $reason,
            'decision_reference' => $reference,
            'decided_by' => $actor,
            'decided_at' => now(),
        ])->save();

        // Only now: a failing redaction reopens the decided item, so the
        // decision must be saved before the job can run (sync queues run it
        // immediately).
        if (isset($redaction)) {
            RedactDocument::dispatch($redaction->id);
        }

        return $item;
    }

    private function assertDecidable(ReviewItem $item, string $decision): void
    {
        if (!in_array($decision, ReviewItem::DECISIONS, true)) {
            throw new DecisionException("Unknown decision [$decision]; use remove, redact or keep.");
        }
        if (!$item->isOpen()) {
            throw new DecisionException("Item #{$item->id} is already decided ({$item->decision}).");
        }
        if ($decision === ReviewItem::DECISION_REDACT && $item->document_id === null) {
            throw new DecisionException("Item #{$item->id} is a bare file with no document text to redact; remove or keep it.");
        }
        if ($item->document_id === null && $item->file_key === null) {
            throw new DecisionException("Item #{$item->id} points at neither a document nor a file.");
        }
        if ($item->document_id !== null && $item->document === null) {
            throw new DecisionException("Item #{$item->id}: the document no longer exists.");
        }
        // A running redaction uploads a redacted copy at the end of its file
        // step; removing underneath it would leave that copy public.
        if ($decision === ReviewItem::DECISION_REMOVE && $item->document_id !== null && PiiRedaction::query()
                ->where('document_id', $item->document_id)
                ->whereNull('reverted_at')
                ->where(fn($q) => $q->where('text_status', PiiRedaction::STATUS_PENDING)
                    ->orWhere(fn($q) => $q->where('text_status', PiiRedaction::STATUS_APPLIED)->where('files_status', PiiRedaction::STATUS_PENDING)))
                ->exists()) {
            throw new DecisionException("Item #{$item->id}: a redaction of this document is still running; wait for it to finish.");
        }
    }

    /**
     * What redacting would do: the replacements and the per-file actions,
     * from the reviewer's strings or else the latest PII assessment.
     */
    private function redactionEffects(ReviewItem $item, array $options): array
    {
        $document = $item->document;

        $active = PiiRedaction::query()->where('document_id', $document->id)->whereNull('reverted_at')
            ->where('text_status', '!=', PiiRedaction::STATUS_FAILED)->exists();
        if ($active) {
            throw new DecisionException("Item #{$item->id}: the document already has a redaction in effect or in progress; revert it in /haldus/isikuandmed before redacting again.");
        }

        $planner = new RedactionPlanner();
        $assessment = null;
        if (!empty($options['strings'])) {
            $plan = $planner->planFromStrings($document, $options['strings']);
        } else {
            $extraction = Assessor::latestUsableExtraction($document);
            $assessment = $extraction ? PiiAssessment::query()->where('extraction_id', $extraction->id)->latest('id')->first() : null;
            if (!$assessment) {
                throw new DecisionException("Item #{$item->id}: the document has no PII assessment yet; pass the strings to remove.");
            }
            $plan = $planner->plan($document, $assessment, $extraction->subjects()->orderBy('id')->get());
        }

        $notFound = [];
        if (!empty($options['strings'])) {
            $notFound = $this->stringsNotFound($document, array_column($plan['replacements'], 'surface'));
            $plan['replacements'] = array_values(array_filter($plan['replacements'], fn($r) => !in_array($r['surface'], $notFound, true)));
            $plan = $planner->planFromStrings($document, array_column($plan['replacements'], 'surface'));
        }

        if (($plan['replacements'] ?? []) === []) {
            throw new DecisionException("Item #{$item->id}: the plan replaces nothing" . (!empty($options['strings']) ? ': none of the strings occur in the document.' : '; pass the strings to remove.'));
        }

        return [
            'plan' => $plan,
            'replacements' => array_map(fn($r) => ['surface' => $r['surface'], 'replacement' => $r['replacement']], $plan['replacements']),
            'files' => array_values($plan['files']),
            'acknowledges_status_changes' => DocumentStatusChange::where('document_id', $document->id)->whereNull('acknowledged_at')->pluck('id')->all(),
            'strings_not_found' => $notFound,
            'public_change' => 'strings replaced in text, title and metadata; listed files replaced by redacted copies or withheld',
            'revertible' => true,
        ];
    }

    /**
     * Strings that occur nowhere the document shows: its text and metadata,
     * plus what only the originals carry (OriginalText).
     *
     * @return string[]
     */
    private function stringsNotFound(\App\Models\Document $document, array $strings): array
    {
        $grounding = Grounding::forDocument($document);
        $original = app(OriginalText::class);
        $extra = [];
        foreach ($document->files as $file) {
            if (OriginalText::applies($file)) {
                $extra['source:' . $file->id] = $original->extra($file);
            }
        }
        $sources = new Grounding($extra);

        return array_values(array_filter($strings, fn($s) => !$grounding->occurs($s) && !$sources->occurs($s)));
    }

    private function startRedaction(ReviewItem $item, array $effects, string $actor): PiiRedaction
    {
        $plan = $effects['plan'];
        $redaction = PiiRedaction::create([
            'document_id' => $item->document_id,
            'assessment_id' => $plan['assessment_id'],
            'applied_by' => $actor,
            'plan' => $plan,
        ]);
        if ($plan['assessment_id']) {
            PiiAssessment::find($plan['assessment_id'])?->review(PiiAssessment::REVIEW_REDACTED, $actor);
        }
        if ($effects['acknowledges_status_changes']) {
            DocumentStatusChange::whereIn('id', $effects['acknowledges_status_changes'])->update([
                'acknowledged_at' => now(),
                'action' => DocumentStatusChange::ACTION_REDACTED,
                'note' => "review item #{$item->id}",
            ]);
        }
        ComplianceAction::create([
            'review_item_id' => $item->id,
            'document_id' => $item->document_id,
            'effect' => 'redaction_dispatched',
            'actor' => $actor,
            'result' => ['redaction_id' => $redaction->id, 'replacements' => count($plan['replacements']), 'files' => array_count_values(array_column($plan['files'], 'action'))],
        ]);
        return $redaction;
    }

    private function planId(ReviewItem $item, string $decision, array $effects): string
    {
        return substr(hash('sha256', json_encode([
            $item->id,
            $item->updated_at?->toIso8601String(),
            $decision,
            $effects,
        ])), 0, 16);
    }
}
