<?php

namespace App\Lib\Compliance;

use App\Lib\Pii\Assessor;
use App\Lib\Pii\Contexts;
use App\Lib\Pii\Extractor;
use App\Lib\Pii\Flags;
use App\Lib\Pii\RedactionPlanner;
use App\Lib\Pii\Rules;
use App\Models\ComplianceAction;
use App\Models\Document;
use App\Models\DocumentStatusChange;
use App\Models\PiiAssessment;
use App\Models\PiiRedaction;
use App\Models\ReviewItem;
use App\Models\TakedownRequest;

/**
 * Everything the admin panel on the document page shows: the review item
 * and its decision, takedown requests, what the source registry says, the
 * PII extraction/assessment/redaction, and the log of actions. One place,
 * so the document page is the single view for all admin work on a document.
 */
class DocumentAdminData
{
    public function for(Document $document): array
    {
        $document->loadMissing(['organisation', 'files.signatures', 'remoteState']);

        $extraction = Assessor::latestUsableExtraction($document);
        $subjects = $extraction ? $extraction->subjects()->orderBy('id')->get() : collect();
        $assessment = $extraction ? PiiAssessment::query()->where('extraction_id', $extraction->id)->latest('id')->first() : null;
        $redactions = $document->piiRedactions()->latest('id')->get();
        $active = $redactions->first(fn(PiiRedaction $r) => $r->isActive() || $r->isInProgress());
        $plan = $assessment && !$active ? (new RedactionPlanner())->plan($document, $assessment, $subjects) : null;

        return [
            'item' => ReviewItem::where('document_id', $document->id)->latest('id')->first(),
            'takedowns' => TakedownRequest::where('document_id', $document->id)->latest('id')->get(),
            'changes' => DocumentStatusChange::where('document_id', $document->id)->orderByDesc('occurred_at')->limit(15)->get(),
            'log' => ComplianceAction::where('document_id', $document->id)->latest('id')->limit(100)->get(),
            'pii' => [
                'extraction' => $extraction,
                'latestAny' => $document->latestPiiExtraction,
                'subjects' => $subjects,
                'assessment' => $assessment,
                'redactions' => $redactions,
                'active' => $active,
                'plan' => $plan,
                'preview' => $plan ? self::preview($document, $plan) : null,
                'contexts' => Contexts::LABELS,
                'flagLabels' => Flags::LABELS,
                'flagged' => Extractor::isFlagged($document),
                'staleAssessment' => $assessment && $assessment->rules_version !== Rules::VERSION,
            ],
        ];
    }

    /**
     * Before/after snippets of the plan's replacements in the stored text.
     *
     * @return array<int, array{file: string, surface: string, before: string, after: string}>
     */
    public static function preview(Document $document, array $plan): array
    {
        $out = [];
        foreach ($document->files as $file) {
            $text = (string)$file->contents;
            if ($text === '') {
                continue;
            }
            foreach ($plan['replacements'] as $r) {
                $pattern = RedactionPlanner::pattern($r['surface'], $r['kind'] ?? 'identifier');
                if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                    $pos = $m[0][1];
                    $start = max(0, $pos - 60);
                    $before = mb_strcut($text, $start, min(mb_strlen($m[0][0]) + 120, strlen($text) - $start));
                    $after = RedactionPlanner::replaceAll($before, $plan['replacements']);
                    $out[] = ['file' => $file->name, 'surface' => $r['surface'], 'before' => $before, 'after' => $after];
                }
            }
        }
        return array_slice($out, 0, 40);
    }
}
