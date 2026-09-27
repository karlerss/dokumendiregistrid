<?php

namespace App\Lib\Pii;

use App\Models\Document;
use App\Models\PiiAssessment;
use App\Models\PiiExtraction;

/**
 * Creates the assessment row for a document's latest usable extraction.
 * Idempotent: returns the existing row when nothing changed since it was
 * computed (same extraction, same rules version, no newer override).
 */
class Assessor
{
    public function __construct(private Rules $rules = new Rules())
    {
    }

    public static function latestUsableExtraction(Document $document): ?PiiExtraction
    {
        return PiiExtraction::query()
            ->where('document_id', $document->id)
            ->where('prompt_version', Extractor::PROMPT_VERSION)
            ->whereIn('status', [PiiExtraction::STATUS_DONE, PiiExtraction::STATUS_TOO_LARGE])
            ->latest('id')
            ->first();
    }

    public function assess(Document $document): ?PiiAssessment
    {
        $extraction = self::latestUsableExtraction($document);
        if (!$extraction) {
            return null;
        }
        $subjects = $extraction->subjects()->orderBy('id')->get();
        $lastOverride = $subjects->max('overridden_at');

        $existing = PiiAssessment::query()
            ->where('extraction_id', $extraction->id)
            ->where('rules_version', Rules::VERSION)
            ->latest('id')
            ->first();
        if ($existing && (!$lastOverride || $existing->computed_at->gt($lastOverride))) {
            return $existing;
        }

        $result = $this->rules->assess($extraction, $subjects);

        return PiiAssessment::create([
            'document_id' => $document->id,
            'extraction_id' => $extraction->id,
            'rules_version' => Rules::VERSION,
            'band' => $result['band'],
            'recommendation' => $result['recommendation'],
            'subject_actions' => $result['subject_actions'],
            'fired_rules' => $result['fired_rules'],
            'computed_at' => now(),
        ]);
    }
}
