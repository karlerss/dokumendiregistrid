<?php

namespace App\Lib\Compliance;

use App\Lib\Pii\Assessor;
use App\Lib\Pii\RedactionPlanner;
use App\Models\Document;
use App\Models\PiiAssessment;
use App\Models\PiiRedaction;
use App\Models\ReviewItem;

/**
 * Batch decision: redact every document whose current PII assessment is in
 * the chosen bands, with that assessment's plan. Each document still gets
 * its own recorded "redact" decision through AdminActions. Shared by the
 * compliance:redact-high-risk command and the /haldus/isikuandmed button.
 */
class BatchRedaction
{
    public const ACTOR = 'haldus:pakett';
    public const DEFAULT_REASON = 'Tuvastati ebaproportsionaalne risk eraelu puutumatusele.';
    public const BANDS = [PiiAssessment::BAND_HIGH, PiiAssessment::BAND_WARN];

    public function __construct(private AdminActions $admin)
    {
    }

    /**
     * @param string[] $bands HIGH and/or WARN
     * @return array{rows: array<int, array>, counts: array{redact: int, skipped: int, failed: int}}
     */
    public function run(array $bands, string $reason, ?string $reference, bool $apply, bool $includeReviewed = false, int $limit = 0, string $actor = self::ACTOR): array
    {
        $documentIds = PiiAssessment::query()->whereIn('band', $bands)->distinct()->pluck('document_id');
        $rows = [];
        $counts = ['redact' => 0, 'skipped' => 0, 'failed' => 0];
        $planner = new RedactionPlanner();

        // Fetch documents one by one rather than through a cursor: an open
        // cursor keeps a SQLite read snapshot alive, and the write lock a
        // decision needs cannot be taken on a connection with a stale
        // snapshot while the daemon keeps committing.
        foreach ($documentIds->sort()->values() as $documentId) {
            if ($limit && $counts['redact'] >= $limit) {
                break;
            }
            $document = Document::find($documentId);
            if (!$document) {
                continue;
            }
            [$skip, $assessment, $plan] = $this->candidate($document, $planner, $bands, $includeReviewed);
            $title = mb_strimwidth($document->title, 0, 60, '…');
            if ($skip) {
                $rows[] = [$document->id, $title, '—', '—', '—', "jäetud vahele: $skip"];
                $counts['skipped']++;
                continue;
            }

            $files = collect($plan['files'])->countBy('action')->map(fn($n, $a) => "{$a}×{$n}")->implode(', ');
            $row = [$document->id, $title, $assessment->band, count($plan['replacements']), $files];
            if (!$apply) {
                $rows[] = [...$row, 'redigeeritakse'];
                $counts['redact']++;
                continue;
            }

            try {
                $this->admin->decide(
                    $document,
                    ReviewItem::DECISION_REDACT,
                    $reason,
                    ReviewItem::SOURCE_PII,
                    ReviewItem::PRIORITY_PII,
                    $reference,
                    $assessment->recommendationLabel(),
                    ['assessment_id' => $assessment->id, 'personal_data' => true],
                    [],
                    $actor,
                );
                $rows[] = [...$row, 'järjekorras'];
                $counts['redact']++;
            } catch (DecisionException $e) {
                $rows[] = [...$row, 'viga: ' . $e->getMessage()];
                $counts['failed']++;
            }
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * Whether the document qualifies, with the same assessment the redact
     * decision will use (latest usable extraction's latest assessment).
     *
     * @return array{0: ?string, 1: ?PiiAssessment, 2: ?array} [skip reason, assessment, plan]
     */
    private function candidate(Document $document, RedactionPlanner $planner, array $bands, bool $includeReviewed): array
    {
        if ($document->isRemoved()) {
            return ['dokument on eemaldatud', null, null];
        }
        $extraction = Assessor::latestUsableExtraction($document);
        $assessment = $extraction ? PiiAssessment::query()->where('extraction_id', $extraction->id)->latest('id')->first() : null;
        if (!$assessment || !in_array($assessment->band, $bands, true)) {
            return ['kehtiv hinnang pole enam valitud tasemel (' . ($assessment->band ?? 'puudub') . ')', null, null];
        }
        if ($assessment->isReviewed() && !$includeReviewed) {
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
}
