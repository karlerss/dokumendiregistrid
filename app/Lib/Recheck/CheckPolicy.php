<?php

namespace App\Lib\Recheck;

use App\Models\Document;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Decides when a document is checked again.
 */
class CheckPolicy
{
    public function __construct(private array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('recheck'));
    }

    /**
     * Next check after a successful probe.
     */
    public function nextCheckAfterSuccess(Document $document, RemoteCheck $check, ?CarbonInterface $now = null): Carbon
    {
        $now = Carbon::instance($now ?? now());
        $intervals = $this->config['intervals'];

        // Restricted or gone: only re-check to notice a reversal.
        if ($check->outcome !== RemoteCheck::PUBLIC) {
            return $now->copy()->addDays((int)$intervals['changed']);
        }

        if ($document->hasOpenTakedownRequest()) {
            return $now->copy()->addDays((int)$intervals['takedown']);
        }

        $registered = $document->registration_date ? Carbon::instance($document->registration_date) : null;
        if ($registered && $registered->gte($now->copy()->subDays((int)$intervals['recent_age_days']))) {
            return $now->copy()->addDays((int)$intervals['recent']);
        }

        return $now->copy()->addDays((int)$intervals['old']);
    }

    /**
     * Next attempt after the Nth consecutive transient error.
     */
    public function nextCheckAfterError(int $consecutiveErrors, ?CarbonInterface $now = null): Carbon
    {
        $now = Carbon::instance($now ?? now());
        $backoff = array_values($this->config['error_backoff_hours']);
        $index = max(0, min($consecutiveErrors - 1, count($backoff) - 1));

        return $now->copy()->addHours((int)$backoff[$index]);
    }
}
