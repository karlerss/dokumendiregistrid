<?php

namespace App\Lib\Recheck;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Daemon liveness and hourly throughput counters, kept in the cache so the
 * admin page and `app:recheck-health` can read them from another process.
 */
class Heartbeat
{
    public const KEY_BEAT = 'recheck.heartbeat';
    public const KEY_PAUSED = 'recheck.paused_hosts';
    public const KEY_STATS_PREFIX = 'recheck.stats.';
    public const KEY_DIGEST_DATE = 'recheck.digest_sent_on';
    public const KEY_STALE_ALERTED = 'recheck.stale_alerted_at';

    private const STATS_TTL_HOURS = 30;

    /** @var array<string, int> */
    private array $pending = ['checks' => 0, 'errors' => 0, 'changes' => 0, 'personal_data' => 0];

    public function count(string $key, int $by = 1): void
    {
        $this->pending[$key] = ($this->pending[$key] ?? 0) + $by;
    }

    /**
     * Write the heartbeat, the paused-host list and the buffered counters.
     *
     * @param array<string, Carbon> $pausedHosts
     */
    public function flush(array $pausedHosts = []): void
    {
        Cache::forever(self::KEY_BEAT, now()->toIso8601String());
        Cache::forever(self::KEY_PAUSED, array_map(fn(Carbon $c) => $c->toIso8601String(), $pausedHosts));

        $bucket = self::KEY_STATS_PREFIX . now()->format('Y-m-d-H');
        $stats = Cache::get($bucket, []);
        foreach ($this->pending as $key => $value) {
            if ($value !== 0) {
                $stats[$key] = ($stats[$key] ?? 0) + $value;
            }
        }
        Cache::put($bucket, $stats, now()->addHours(self::STATS_TTL_HOURS));

        $this->pending = ['checks' => 0, 'errors' => 0, 'changes' => 0, 'personal_data' => 0];
    }

    public static function lastBeat(): ?Carbon
    {
        $raw = Cache::get(self::KEY_BEAT);
        return $raw ? Carbon::parse($raw) : null;
    }

    public static function isStale(?int $minutes = null): bool
    {
        $minutes ??= (int)config('recheck.heartbeat_stale_minutes', 30);
        $beat = self::lastBeat();
        return $beat === null || $beat->lt(now()->subMinutes($minutes));
    }

    /**
     * @return array<string, Carbon>
     */
    public static function pausedHosts(): array
    {
        $raw = Cache::get(self::KEY_PAUSED, []);
        $out = [];
        foreach ($raw as $host => $until) {
            $until = Carbon::parse($until);
            if ($until->isFuture()) {
                $out[$host] = $until;
            }
        }
        return $out;
    }

    /**
     * Summed counters over the last $hours hourly buckets.
     *
     * @return array<string, int>
     */
    public static function statsForLastHours(int $hours = 24): array
    {
        $totals = ['checks' => 0, 'errors' => 0, 'changes' => 0, 'personal_data' => 0];
        $cursor = now()->startOfHour();
        for ($i = 0; $i < $hours; $i++) {
            $bucket = Cache::get(self::KEY_STATS_PREFIX . $cursor->format('Y-m-d-H'), []);
            foreach ($bucket as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + (int)$value;
            }
            $cursor->subHour();
        }
        return $totals;
    }
}
