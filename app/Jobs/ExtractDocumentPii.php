<?php

namespace App\Jobs;

use App\Lib\Pii\Extractor;
use App\Models\PiiExtraction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one pending extraction row. Failures are recorded on the row (and
 * re-queued by pii:enqueue up to MAX_ATTEMPTS); the job itself never retries.
 */
class ExtractDocumentPii implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public int $extractionId)
    {
    }

    public function handle(Extractor $extractor): void
    {
        $extraction = PiiExtraction::find($this->extractionId);
        if (!$extraction || $extraction->status !== PiiExtraction::STATUS_PENDING) {
            return;
        }
        $extractor->run($extraction);
        $extraction->refresh();
        if (in_array($extraction->status, [PiiExtraction::STATUS_DONE, PiiExtraction::STATUS_TOO_LARGE], true)) {
            AssessDocumentPii::dispatch($extraction->document_id);
        }
    }
}
