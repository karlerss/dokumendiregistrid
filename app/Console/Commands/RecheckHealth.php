<?php

namespace App\Console\Commands;

use App\Lib\Recheck\Heartbeat;
use App\Mail\RecheckHealthMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Meant for cron (hourly): mails the admin once when the daemon's heartbeat
 * goes stale or a host has been paused for more than a day, and once more
 * when it recovers.
 */
class RecheckHealth extends Command
{
    protected $signature = 'app:recheck-health';

    protected $description = 'Alert the admin if the re-check daemon has stopped or a registry host stays blocked';

    public function handle(): int
    {
        $problems = [];

        if (Heartbeat::isStale()) {
            $beat = Heartbeat::lastBeat();
            $problems[] = $beat
                ? "Daemoni viimane elumärk oli {$beat->diffForHumans()} ({$beat->toDateTimeString()})."
                : 'Daemon ei ole kunagi elumärki andnud.';
        }

        foreach (Heartbeat::pausedHosts() as $host => $until) {
            if ($until->gt(now()->addHours(12))) {
                $problems[] = "Host $host on peatatud kuni {$until->toDateTimeString()} (tõenäoliselt robotkontroll).";
            }
        }

        $alertedAt = Cache::get(Heartbeat::KEY_STALE_ALERTED);
        $to = config('mail.admin.address');

        if ($problems) {
            foreach ($problems as $p) {
                $this->warn($p);
            }
            if ($alertedAt === null && $to) {
                Mail::to($to)->send(new RecheckHealthMail($problems, recovered: false));
                Cache::forever(Heartbeat::KEY_STALE_ALERTED, now()->toIso8601String());
                $this->info('Alert sent.');
            }
            return self::FAILURE;
        }

        $this->info('Healthy.');
        if ($alertedAt !== null) {
            if ($to) {
                Mail::to($to)->send(new RecheckHealthMail([], recovered: true));
            }
            Cache::forget(Heartbeat::KEY_STALE_ALERTED);
        }
        return self::SUCCESS;
    }
}
