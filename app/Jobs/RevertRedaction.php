<?php

namespace App\Jobs;

use App\Lib\Pii\Redactor;
use App\Models\PiiRedaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RevertRedaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public int $redactionId, public string $by)
    {
    }

    public function handle(Redactor $redactor): void
    {
        $redaction = PiiRedaction::find($this->redactionId);
        if ($redaction) {
            $redactor->revert($redaction, $this->by);
        }
    }
}
