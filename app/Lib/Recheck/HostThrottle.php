<?php

namespace App\Lib\Recheck;

use Carbon\Carbon;
use Closure;

/**
 * Per-host request spacing, a global request-rate cap, and a per-host circuit
 * breaker. In-memory; the daemon is a single process.
 */
class HostThrottle
{
    /** @var array<string, float> host => monotonic seconds of last request */
    private array $lastRequestAt = [];

    private float $lastGlobalRequestAt = 0.0;

    /** @var array<string, Carbon> host => paused until */
    private array $pausedUntil = [];

    /** @var array<string, int> */
    private array $consecutiveErrors = [];

    /** @var array<string, int> how many times the host has been paused in a row */
    private array $pauseStreak = [];

    private Closure $sleeper;
    private Closure $clock;

    /**
     * @param int[] $pauseMinutes
     * @param Closure(float): void|null $sleeper receives seconds to sleep
     * @param Closure(): float|null $clock returns monotonic seconds
     */
    public function __construct(
        private int $perHostDelayMs,
        private float $globalMaxRps,
        private int $errorThreshold,
        private array $pauseMinutes,
        private int $botCheckPauseMinutes,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
    ) {
        $this->sleeper = $sleeper ?? fn(float $seconds) => usleep((int)round($seconds * 1_000_000));
        $this->clock = $clock ?? fn() => microtime(true);
    }

    public static function fromConfig(?Closure $sleeper = null, ?Closure $clock = null): self
    {
        $c = config('recheck');
        return new self(
            (int)$c['per_host_delay_ms'],
            (float)$c['global_max_rps'],
            (int)$c['host_error_threshold'],
            array_values($c['host_pause_minutes']),
            (int)$c['bot_check_pause_minutes'],
            $sleeper,
            $clock,
        );
    }

    public static function host(string $url): string
    {
        return mb_strtolower((string)parse_url($url, PHP_URL_HOST));
    }

    public function isPaused(string $host): bool
    {
        if (!isset($this->pausedUntil[$host])) {
            return false;
        }
        if ($this->pausedUntil[$host]->isPast()) {
            unset($this->pausedUntil[$host]);
            return false;
        }
        return true;
    }

    /**
     * @return array<string, Carbon> host => until
     */
    public function pausedHosts(): array
    {
        foreach (array_keys($this->pausedUntil) as $host) {
            $this->isPaused($host); // drops expired entries
        }
        return $this->pausedUntil;
    }

    /**
     * Block until a request to $host is allowed, then record it.
     */
    public function waitFor(string $host): void
    {
        $now = ($this->clock)();

        $perHostWait = 0.0;
        if (isset($this->lastRequestAt[$host])) {
            $perHostWait = ($this->lastRequestAt[$host] + $this->perHostDelayMs / 1000) - $now;
        }

        $globalWait = 0.0;
        if ($this->globalMaxRps > 0 && $this->lastGlobalRequestAt > 0) {
            $globalWait = ($this->lastGlobalRequestAt + 1 / $this->globalMaxRps) - $now;
        }

        $wait = max(0.0, $perHostWait, $globalWait);
        if ($wait > 0) {
            ($this->sleeper)($wait);
            $now += $wait;
        }

        $this->lastRequestAt[$host] = $now;
        $this->lastGlobalRequestAt = $now;
    }

    public function recordSuccess(string $host): void
    {
        $this->consecutiveErrors[$host] = 0;
        $this->pauseStreak[$host] = 0;
    }

    /**
     * @return Carbon|null the pause deadline if this error paused the host
     */
    public function recordError(string $host, RemoteCheck $check): ?Carbon
    {
        if ($check->isBotCheck()) {
            $this->consecutiveErrors[$host] = 0;
            return $this->pause($host, $this->botCheckPauseMinutes);
        }

        $this->consecutiveErrors[$host] = ($this->consecutiveErrors[$host] ?? 0) + 1;

        if ($this->consecutiveErrors[$host] >= $this->errorThreshold) {
            $this->consecutiveErrors[$host] = 0;
            $streak = $this->pauseStreak[$host] ?? 0;
            $minutes = $this->pauseMinutes[min($streak, count($this->pauseMinutes) - 1)];
            $this->pauseStreak[$host] = $streak + 1;
            return $this->pause($host, (int)$minutes);
        }

        return null;
    }

    public function pause(string $host, int $minutes): Carbon
    {
        $until = now()->addMinutes($minutes);
        $this->pausedUntil[$host] = $until;
        return $until;
    }

    public function consecutiveErrors(string $host): int
    {
        return $this->consecutiveErrors[$host] ?? 0;
    }
}
