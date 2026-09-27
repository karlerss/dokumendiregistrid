<?php

namespace App\Console\Commands;

use App\Jobs\AssessDocumentPii;
use App\Jobs\ExtractDocumentPii;
use App\Lib\Pii\Extractor;
use App\Lib\Pii\Rules;
use App\Models\Document;
use App\Models\PiiExtraction;
use App\Models\PiiSubject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled every minute. Dispatches queue jobs for whatever is behind:
 *
 *  1. pending extraction rows never dispatched, or dispatched too long ago;
 *  2. usable extractions without an assessment at the current rules version,
 *     and documents whose subjects were overridden after the last assessment;
 *  3. new automatic extractions from the selection, subject to the daily
 *     token cap (admin-requested rows are dispatched in step 1 regardless).
 *
 * Only writes pii_* rows and queue jobs. Nothing visible changes.
 */
class PiiEnqueue extends Command
{
    public const KEY_LAST_RUN = 'pii.enqueue.last_run';
    public const KEY_CAP_HIT = 'pii.enqueue.cap_hit_on';

    protected $signature = 'pii:enqueue {--dry-run : Report what would be dispatched}';
    protected $description = 'Dispatch PII extraction and assessment jobs for documents behind the current versions';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        $requeueAfter = now()->subMinutes((int)config('pii.requeue_after_minutes'));

        // 1. pending rows
        $pending = PiiExtraction::query()
            ->where('status', PiiExtraction::STATUS_PENDING)
            ->where(fn($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', $requeueAfter))
            ->orderBy('id')
            ->limit(50)
            ->get();
        foreach ($pending as $row) {
            if (!$dry) {
                $row->forceFill(['queued_at' => now()])->save();
                ExtractDocumentPii::dispatch($row->id);
            }
        }

        // 2. assessments
        $needAssessment = DB::table('pii_extractions as e')
            ->select('e.document_id')
            ->where('e.prompt_version', Extractor::PROMPT_VERSION)
            ->whereIn('e.status', [PiiExtraction::STATUS_DONE, PiiExtraction::STATUS_TOO_LARGE])
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('pii_assessments as a')
                    ->whereColumn('a.extraction_id', 'e.id')
                    ->where('a.rules_version', Rules::VERSION);
            })
            ->distinct()
            ->limit(200)
            ->pluck('document_id');
        $overridden = PiiSubject::query()
            ->whereNotNull('overridden_at')
            ->whereIn('extraction_id', function ($q) {
                $q->select('id')->from('pii_extractions')->where('prompt_version', Extractor::PROMPT_VERSION);
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('pii_assessments as a')
                    ->whereColumn('a.extraction_id', 'pii_subjects.extraction_id')
                    ->where('a.rules_version', Rules::VERSION)
                    ->whereColumn('a.computed_at', '>', 'pii_subjects.overridden_at');
            })
            ->distinct()
            ->limit(200)
            ->pluck('document_id');
        $assess = $needAssessment->merge($overridden)->unique();
        foreach ($assess as $documentId) {
            if (!$dry) {
                AssessDocumentPii::dispatch((int)$documentId);
            }
        }

        // 3. automatic selection under the cap
        $cap = (int)config('pii.daily_token_cap');
        $used = Extractor::tokensUsedToday();
        $dispatchedNew = 0;
        if ($used < $cap) {
            $batch = (int)config('pii.enqueue_batch');
            $docs = Extractor::selectionQuery()->limit($batch)->get();
            foreach ($docs as $doc) {
                /** @var Document $doc */
                if (!$dry) {
                    $row = Extractor::request($doc, PiiExtraction::REQUESTED_BY_WORKER);
                    $row->forceFill(['queued_at' => now()])->save();
                    ExtractDocumentPii::dispatch($row->id);
                }
                $dispatchedNew++;
            }
            if (!$dry) {
                Cache::forget(self::KEY_CAP_HIT);
            }
        } elseif (!$dry) {
            Cache::forever(self::KEY_CAP_HIT, now()->toIso8601String());
        }

        if (!$dry) {
            Cache::forever(self::KEY_LAST_RUN, now()->toIso8601String());
        }

        $this->line(sprintf(
            '%spending re-dispatched: %d, assessments: %d, new extractions: %d, tokens today: %d / %d',
            $dry ? '[dry-run] ' : '', $pending->count(), $assess->count(), $dispatchedNew, $used, $cap
        ));
        return self::SUCCESS;
    }
}
