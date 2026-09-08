<?php

namespace Tests\Feature;

use App\Lib\Recheck\Heartbeat;
use App\Mail\RecheckHealthMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecheckHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config()->set('mail.admin.address', 'admin@example.com');
    }

    public function test_healthy_daemon_sends_nothing(): void
    {
        Cache::forever(Heartbeat::KEY_BEAT, now()->subMinutes(5)->toIso8601String());

        $this->artisan('app:recheck-health')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_stale_heartbeat_alerts_once_and_recovers_once(): void
    {
        Cache::forever(Heartbeat::KEY_BEAT, now()->subHours(3)->toIso8601String());

        $this->artisan('app:recheck-health')->assertFailed();
        $this->artisan('app:recheck-health')->assertFailed();

        Mail::assertSent(RecheckHealthMail::class, 1);
        Mail::assertSent(RecheckHealthMail::class, fn(RecheckHealthMail $m) => !$m->recovered && $m->hasTo('admin@example.com'));

        Cache::forever(Heartbeat::KEY_BEAT, now()->toIso8601String());
        $this->artisan('app:recheck-health')->assertSuccessful();
        $this->artisan('app:recheck-health')->assertSuccessful();

        Mail::assertSent(RecheckHealthMail::class, 2);
        Mail::assertSent(RecheckHealthMail::class, fn(RecheckHealthMail $m) => $m->recovered);
    }

    public function test_never_started_daemon_alerts(): void
    {
        $this->artisan('app:recheck-health')->assertFailed();
        Mail::assertSent(RecheckHealthMail::class, 1);
    }

    public function test_long_paused_host_alerts(): void
    {
        Cache::forever(Heartbeat::KEY_BEAT, now()->toIso8601String());
        Cache::forever(Heartbeat::KEY_PAUSED, ['adr.politsei.ee' => now()->addHours(23)->toIso8601String()]);

        $this->artisan('app:recheck-health')
            ->expectsOutputToContain('adr.politsei.ee')
            ->assertFailed();
        Mail::assertSent(RecheckHealthMail::class, fn(RecheckHealthMail $m) => str_contains(implode(' ', $m->problems), 'adr.politsei.ee'));
    }

    public function test_short_pause_is_not_an_alert(): void
    {
        Cache::forever(Heartbeat::KEY_BEAT, now()->toIso8601String());
        Cache::forever(Heartbeat::KEY_PAUSED, ['adr.rik.ee' => now()->addMinutes(30)->toIso8601String()]);

        $this->artisan('app:recheck-health')->assertSuccessful();
        Mail::assertNothingSent();
    }
}
