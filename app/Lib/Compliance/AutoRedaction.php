<?php

namespace App\Lib\Compliance;

use App\Models\ComplianceAction;
use App\Models\PiiAssessment;
use Illuminate\Database\Eloquent\Builder;

/**
 * The site owner's standing rule: a HIGH or WARN assessment left unreviewed
 * for config('compliance.auto_redact_after_days') days is redacted with its
 * plan. Each document still gets its own recorded "redact" decision (actor
 * "automaat"); documents with nothing to replace or a redaction already in
 * place are skipped by BatchRedaction.
 */
class AutoRedaction
{
    public const ACTOR = 'automaat';
    public const BANDS = [PiiAssessment::BAND_HIGH, PiiAssessment::BAND_WARN];

    public function __construct(private BatchRedaction $batch)
    {
    }

    public static function days(): int
    {
        return max(0, (int)config('compliance.auto_redact_after_days'));
    }

    /** Current unreviewed HIGH/WARN assessments on documents still on the site. */
    public static function pending(): Builder
    {
        return PiiAssessment::query()
            ->whereIn('id', PiiAssessment::query()->selectRaw('max(id)')->groupBy('document_id'))
            ->whereIn('band', self::BANDS)
            ->whereNull('reviewed_at')
            ->whereHas('document', fn($q) => $q->whereNull('removed_at'));
    }

    /** Those whose review time is up. */
    public static function due(): Builder
    {
        return self::pending()->where('created_at', '<=', now()->subDays(self::days()));
    }

    public static function reason(): string
    {
        return 'Isikuandmete hinnang (kõrge või hoiatus) jäi ' . self::days() . ' päeva jooksul läbi vaatamata; hinnangu plaan rakendati automaatselt.';
    }

    /** @return array{rows: array, counts: array{redact: int, skipped: int, failed: int}}|null null when the rule is off */
    public function run(bool $apply): ?array
    {
        if (self::days() === 0) {
            return null;
        }
        $ids = self::due()->orderBy('document_id')->pluck('document_id')->all();
        $result = $this->batch->runForDocuments($ids, self::reason(), null, $apply, self::ACTOR, self::BANDS);

        if ($apply && $ids) {
            ComplianceAction::create([
                'effect' => 'auto_redaction_completed',
                'actor' => self::ACTOR,
                'result' => ['days' => self::days(), 'documents' => count($ids)] + $result['counts'],
            ]);
        }
        return $result;
    }
}
