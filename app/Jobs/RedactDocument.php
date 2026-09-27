<?php

namespace App\Jobs;

use App\Lib\Pii\Redactor;
use App\Models\PiiRedaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Executes an admin-triggered redaction: text step, then file step.
 * Dispatched only from the admin UI.
 */
class RedactDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public int $redactionId)
    {
    }

    public function handle(Redactor $redactor): void
    {
        $redaction = PiiRedaction::find($this->redactionId);
        if (!$redaction || $redaction->isReverted()) {
            return;
        }
        if ($redaction->text_status === PiiRedaction::STATUS_PENDING) {
            if (!$redactor->applyText($redaction)) {
                return;
            }
        }
        if ($redaction->files_status === PiiRedaction::STATUS_PENDING) {
            $redactor->applyFiles($redaction);
        }
    }
}
