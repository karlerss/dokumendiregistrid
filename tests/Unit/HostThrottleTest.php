<?php

namespace Tests\Unit;

use App\Lib\Recheck\HostThrottle;
use App\Lib\Recheck\RemoteCheck;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class HostThrottleTest extends TestCase
{
    /** @var float[] */
    private array $sleeps = [];
    private float $clock = 1000.0;

    private function throttle(int $perHostMs = 750, float $rps = 3, int $threshold = 5, array $pauses = [15, 30, 60], int $botPause = 1440): HostThrottle
    {
        $this->sleeps = [];
        $this->clock = 1000.0;
        return new HostThrottle(
            $perHostMs,
            $rps,
            $threshold,
            $pauses,
            $botPause,
            function (float $s) {
                $this->sleeps[] = round($s, 3);
                $this->clock += $s;
            },
            fn() => $this->clock,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_host_is_extracted_from_url(): void
    {
        $this->assertSame('adr.rik.ee', HostThrottle::host('https://adr.rik.ee/som/dokument/1'));
        $this->assertSame('dhs.tallinn.ee', HostThrottle::host('https://dhs.tallinn.ee/atp/?c_tpl=1092&dok_id=1'));
    }

    public function test_first_request_does_not_sleep(): void
    {
        $t = $this->throttle();
        $t->waitFor('a.example');
        $this->assertSame([], $this->sleeps);
    }

    public function test_second_request_to_same_host_waits_for_per_host_delay(): void
    {
        $t = $this->throttle(perHostMs: 750, rps: 100);
        $t->waitFor('a.example');
        $t->waitFor('a.example');
        $this->assertSame([0.75], $this->sleeps);
    }

    public function test_requests_to_different_hosts_are_only_bound_by_global_rate(): void
    {
        $t = $this->throttle(perHostMs: 750, rps: 4);
        $t->waitFor('a.example');
        $t->waitFor('b.example');
        $this->assertSame([0.25], $this->sleeps);
    }

    public function test_elapsed_time_reduces_the_wait(): void
    {
        $t = $this->throttle(perHostMs: 1000, rps: 100);
        $t->waitFor('a.example');
        $this->clock += 0.6;
        $t->waitFor('a.example');
        $this->assertSame([0.4], $this->sleeps);
    }

    public function test_host_is_paused_after_threshold_consecutive_errors_with_growing_pauses(): void
    {
        Carbon::setTestNow('2026-09-07 10:00:00');
        $t = $this->throttle(threshold: 2, pauses: [15, 30]);
        $error = RemoteCheck::error(RemoteCheck::ERROR_HTTP_5XX, 500);

        $this->assertNull($t->recordError('a.example', $error));
        $this->assertFalse($t->isPaused('a.example'));

        $until = $t->recordError('a.example', $error);
        $this->assertNotNull($until);
        $this->assertSame('2026-09-07 10:15:00', $until->toDateTimeString());
        $this->assertTrue($t->isPaused('a.example'));
        $this->assertArrayHasKey('a.example', $t->pausedHosts());

        // Pause expires.
        Carbon::setTestNow('2026-09-07 10:16:00');
        $this->assertFalse($t->isPaused('a.example'));

        // Next streak escalates to the second pause length.
        $t->recordError('a.example', $error);
        $until = $t->recordError('a.example', $error);
        $this->assertSame('2026-09-07 10:46:00', $until->toDateTimeString());

        // A success resets the escalation.
        Carbon::setTestNow('2026-09-07 11:00:00');
        $t->recordSuccess('a.example');
        $t->recordError('a.example', $error);
        $until = $t->recordError('a.example', $error);
        $this->assertSame('2026-09-07 11:15:00', $until->toDateTimeString());
    }

    public function test_success_resets_consecutive_error_count(): void
    {
        $t = $this->throttle(threshold: 3);
        $error = RemoteCheck::error(RemoteCheck::ERROR_TIMEOUT);
        $t->recordError('a.example', $error);
        $t->recordError('a.example', $error);
        $t->recordSuccess('a.example');
        $t->recordError('a.example', $error);
        $this->assertSame(1, $t->consecutiveErrors('a.example'));
        $this->assertFalse($t->isPaused('a.example'));
    }

    public function test_bot_check_pauses_host_immediately_for_the_long_pause(): void
    {
        Carbon::setTestNow('2026-09-07 10:00:00');
        $t = $this->throttle(threshold: 5, botPause: 1440);

        $until = $t->recordError('adr.politsei.ee', RemoteCheck::error(RemoteCheck::ERROR_BOT_CHECK, 302));

        $this->assertSame('2026-09-08 10:00:00', $until->toDateTimeString());
        $this->assertTrue($t->isPaused('adr.politsei.ee'));
        $this->assertFalse($t->isPaused('adr.rik.ee'));
    }
}
