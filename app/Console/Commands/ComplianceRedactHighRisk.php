<?php

namespace App\Console\Commands;

use App\Lib\Compliance\AdminActions;
use App\Lib\Compliance\DecisionException;
use App\Lib\Pii\Assessor;
use App\Lib\Pii\RedactionPlanner;
use App\Models\Document;
use App\Models\PiiAssessment;
use App\Models\PiiRedaction;
use App\Models\ReviewItem;
use Illuminate\Console\Command;

/**
 * One-off batch decision: redact every document whose current PII
 * assessment is HIGH, using that assessment's plan. Each document still
 * gets its own recorded "redact" decision (review item, reason, actor,
 * audit log) exactly as if decided in /haldus; the redaction runs on the
 * queue worker. Dry run unless --apply.
 */
class ComplianceRedactHighRisk extends Command
{
    public const ACTOR = 'haldus:pakett';

    protected $signature = 'compliance:redact-high-risk
        {--apply : Carry out the redactions (default: dry run)}
        {--reason=Tuvastati ebaproportsionaalne risk eraelu puutumatusele. : Reason recorded on every decision}
        {--reference= : Optional reference recorded on every decision}
        {--include-reviewed : Also redact documents whose HIGH assessment was already reviewed}
        {--limit=0 : Stop after this many redactions (0 = no limit)}';

    protected $description = 'Redact all documents whose current PII assessment is HIGH, as one recorded decision per document';

    public function handle(AdminActions $admin): int
    {
        $apply = (bool)$this->option('apply');
        $reason = trim((string)$this->option('reason'));
        $reference = $this->option('reference') ?: null;
        $limit = (int)$this->option('limit');
        if ($reason === '') {
            $this->error('A reason is required.');
            return self::FAILURE;
        }

        $documentIds = PiiAssessment::query()->where('band', PiiAssessment::BAND_HIGH)->distinct()->pluck('document_id');
        $rows = [];
        $counts = ['redact' => 0, 'skipped' => 0, 'failed' => 0];
        $planner = new RedactionPlanner();

        foreach (Document::query()->whereIn('id', $documentIds)->orderBy('id')->cursor() as $document) {
            if ($limit && $counts['redact'] >= $limit) {
                break;
            }
            [$skip, $assessment, $plan] = $this->candidate($document, $planner);
            if ($skip) {
                $rows[] = [$document->id, $this->title($document), '—', '—', "jäetud vahele: $skip"];
                $counts['skipped']++;
                continue;
            }

            $files = collect($plan['files'])->countBy('action')->map(fn($n, $a) => "{$a}×{$n}")->implode(', ');
            if (!$apply) {
                $rows[] = [$document->id, $this->title($document), count($plan['replacements']), $files, 'redigeeritakse'];
                $counts['redact']++;
                continue;
            }

            try {
                $admin->decide(
                    $document,
                    ReviewItem::DECISION_REDACT,
                    $reason,
                    ReviewItem::SOURCE_PII,
                    ReviewItem::PRIORITY_PII,
                    $reference,
                    $assessment->recommendationLabel(),
                    ['assessment_id' => $assessment->id, 'personal_data' => true],
                    [],
                    self::ACTOR,
                );
                $rows[] = [$document->id, $this->title($document), count($plan['replacements']), $files, 'järjekorras'];
                $counts['redact']++;
            } catch (DecisionException $e) {
                $rows[] = [$document->id, $this->title($document), count($plan['replacements']), $files, 'viga: ' . $e->getMessage()];
                $counts['failed']++;
            }
        }

        $this->table(['Dokument', 'Pealkiri', 'Asendusi', 'Failid', 'Tulemus'], $rows);
        $this->info(sprintf('%s: %d redigeeritakse, %d jäetud vahele, %d vigu. Põhjus: „%s“',
            $apply ? 'Tehtud' : 'Kuivkäivitus (midagi ei muudetud; lisa --apply)',
            $counts['redact'], $counts['skipped'], $counts['failed'], $reason));
        if ($apply && $counts['redact']) {
            $this->line('Redigeerimised töötab läbi järjekorra töötaja; seis on näha dokumendi lehel.');
        }
        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether the document qualifies, with the same assessment the redact
     * decision will use (latest usable extraction's latest assessment).
     *
     * @return array{0: ?string, 1: ?PiiAssessment, 2: ?array} [skip reason, assessment, plan]
     */
    private function candidate(Document $document, RedactionPlanner $planner): array
    {
        if ($document->isRemoved()) {
            return ['dokument on eemaldatud', null, null];
        }
        $extraction = Assessor::latestUsableExtraction($document);
        $assessment = $extraction ? PiiAssessment::query()->where('extraction_id', $extraction->id)->latest('id')->first() : null;
        if (!$assessment || $assessment->band !== PiiAssessment::BAND_HIGH) {
            return ['kehtiv hinnang pole enam HIGH', null, null];
        }
        if ($assessment->isReviewed() && !$this->option('include-reviewed')) {
            return ["hinnang juba läbi vaadatud ({$assessment->review_action})", null, null];
        }
        $active = PiiRedaction::query()->where('document_id', $document->id)->whereNull('reverted_at')
            ->where('text_status', '!=', PiiRedaction::STATUS_FAILED)->exists();
        if ($active) {
            return ['redigeerimine juba kehtib või käib', null, null];
        }
        $plan = $planner->plan($document, $assessment, $extraction->subjects()->orderBy('id')->get());
        if (($plan['replacements'] ?? []) === []) {
            return ['plaanis pole midagi asendada', null, null];
        }
        return [null, $assessment, $plan];
    }

    private function title(Document $document): string
    {
        return mb_strimwidth($document->title, 0, 60, '…');
    }
}
