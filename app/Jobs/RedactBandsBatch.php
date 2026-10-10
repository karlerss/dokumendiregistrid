<?php

namespace App\Jobs;

use App\Lib\Compliance\BatchRedaction;
use App\Models\ComplianceAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The /haldus/isikuandmed "redact all" button: runs the batch decision on
 * the queue (planning reads originals from storage, too slow for a web
 * request). Each document gets its own decision and RedactDocument job.
 */
class RedactBandsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 3600;

    /** @param string[] $bands */
    public function __construct(public array $bands, public string $reason, public ?string $reference = null)
    {
    }

    public function handle(BatchRedaction $batch): void
    {
        $result = $batch->run($this->bands, $this->reason, $this->reference, apply: true);

        ComplianceAction::create([
            'effect' => 'batch_redaction_completed',
            'actor' => BatchRedaction::ACTOR,
            'result' => ['bands' => $this->bands, 'reason' => $this->reason, 'reference' => $this->reference] + $result['counts'],
        ]);
    }
}
