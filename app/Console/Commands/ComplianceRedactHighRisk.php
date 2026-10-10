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
 * assessment is in the chosen band(s) (HIGH by default; --band=WARN or
 * both), using that assessment's plan. Each document still
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
        {--band=* : Assessment band(s) to redact: HIGH (default), WARN, or both (--band=HIGH --band=WARN)}
        {--include-reviewed : Also redact documents whose assessment was already reviewed}
        {--limit=0 : Stop after this many redactions (0 = no limit)}';

    /** @var string[] */
    private array $bands = [PiiAssessment::BAND_HIGH];

    protected $description = 'Redact all documents whose current PII assessment is HIGH (or WARN with --band), as one recorded decision per document';

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
        $bands = array_values(array_unique(array_map('strtoupper', $this->option('band') ?: [PiiAssessment::BAND_HIGH])));
        $unknown = array_diff($bands, [PiiAssessment::BAND_HIGH, PiiAssessment::BAND_WARN]);
        if ($unknown) {
            $this->error('Unknown band: ' . implode(', ', $unknown) . ' (use HIGH and/or WARN).');
            return self::FAILURE;
        }
        $this->bands = $bands;
        $this->info('Tasemed: ' . implode(', ', $bands));

        $documentIds = PiiAssessment::query()->whereIn('band', $bands)->distinct()->pluck('document_id');
        $rows = [];
        $counts = ['redact' => 0, 'skipped' => 0, 'failed' => 0];
        $planner = new RedactionPlanner();

        // Fetch documents one by one rather than through a cursor: an open
        // cursor keeps a SQLite read snapshot alive, and the write lock a
        // decision needs cannot be taken on a connection with a stale
        // snapshot while the daemon keeps committing.
        foreach ($documentIds->sort()->values() as $documentId) {
            $document = Document::find($documentId);
            if (!$document) {
                continue;
            }
            if ($limit && $counts['redact'] >= $limit) {
                break;
            }
            [$skip, $assessment, $plan] = $this->candidate($document, $planner);
            if ($skip) {
                $rows[] = [$document->id, $this->title($document), '—', '—', '—', "jäetud vahele: $skip"];
                $counts['skipped']++;
                continue;
            }

            $files = collect($plan['files'])->countBy('action')->map(fn($n, $a) => "{$a}×{$n}")->implode(', ');
            if (!$apply) {
                $rows[] = [$document->id, $this->title($document), $assessment->band, count($plan['replacements']), $files, 'redigeeritakse'];
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
                $rows[] = [$document->id, $this->title($document), $assessment->band, count($plan['replacements']), $files, 'järjekorras'];
                $counts['redact']++;
            } catch (DecisionException $e) {
                $rows[] = [$document->id, $this->title($document), $assessment->band, count($plan['replacements']), $files, 'viga: ' . $e->getMessage()];
                $counts['failed']++;
            }
        }

        $this->table(['Dokument', 'Pealkiri', 'Tase', 'Asendusi', 'Failid', 'Tulemus'], $rows);
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
        if (!$assessment || !in_array($assessment->band, $this->bands, true)) {
            return ['kehtiv hinnang pole enam valitud tasemel (' . ($assessment->band ?? 'puudub') . ')', null, null];
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
