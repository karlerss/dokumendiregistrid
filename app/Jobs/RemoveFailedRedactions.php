<?php

namespace App\Jobs;

use App\Lib\Compliance\BatchRedaction;
use App\Lib\Compliance\FailedRedactionRemoval;
use App\Models\ComplianceAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The /haldus/isikuandmed "remove failed redactions" button: removing deletes
 * every stored copy and purges the CDN, too slow for a web request.
 */
class RemoveFailedRedactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(public string $reason, public ?string $reference = null)
    {
    }

    public function handle(FailedRedactionRemoval $removal): void
    {
        $result = $removal->run($this->reason, $this->reference);

        ComplianceAction::create([
            'effect' => 'batch_removal_completed',
            'actor' => BatchRedaction::ACTOR,
            'result' => ['reason' => $this->reason, 'reference' => $this->reference] + $result,
        ]);
    }
}
