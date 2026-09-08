<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remote re-check daemon
    |--------------------------------------------------------------------------
    |
    | Settings for `php artisan app:recheck-daemon`, which periodically
    | re-fetches every document that was public at ingest and records changes
    | to its access restriction or its existence at the source registry.
    |
    */

    // Minimum spacing between two requests to the same host (milliseconds).
    'per_host_delay_ms' => (int) env('RECHECK_PER_HOST_DELAY_MS', 750),

    // Hard cap on requests per second across all hosts.
    'global_max_rps' => (float) env('RECHECK_GLOBAL_MAX_RPS', 3),

    // Documents pulled from the queue per loop iteration.
    'batch_size' => (int) env('RECHECK_BATCH_SIZE', 200),

    // Seconds to sleep when the queue has nothing due.
    'idle_sleep_seconds' => (int) env('RECHECK_IDLE_SLEEP', 60),

    // HTTP timeouts (seconds).
    'timeout' => 15,
    'connect_timeout' => 5,

    // Re-check intervals (days) after a successful check.
    'intervals' => [
        // Document has an unresolved or accepted takedown request.
        'takedown' => 7,
        // Registered less than `recent_age_days` ago.
        'recent' => 14,
        'recent_age_days' => 180,
        // Everything else that is still public at the source.
        'old' => 45,
        // Remotely restricted or gone: only to notice reversals.
        'changed' => 90,
    ],

    // Backoff (hours) after the Nth consecutive transient error; the last
    // value repeats.
    'error_backoff_hours' => [1, 6, 24, 72],

    // Consecutive transient errors from one host before the host is paused.
    'host_error_threshold' => 5,

    // Pause lengths (minutes) for the Nth consecutive host pause; the last
    // value repeats.
    'host_pause_minutes' => [15, 30, 60],

    // Pause length (minutes) when a host answers with a bot check.
    'bot_check_pause_minutes' => 1440,

    // Hour of day (server time) at which the daemon sends the daily digest.
    'digest_hour' => 8,

    // Heartbeat age (minutes) after which `app:recheck-health` alerts.
    'heartbeat_stale_minutes' => 30,
];
