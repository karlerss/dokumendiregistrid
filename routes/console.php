<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// PII pipeline: dispatch extraction / assessment jobs for whatever is behind
// the current prompt or rules version (see pii_plan.md §3). Needs
// `* * * * * php artisan schedule:run` in the app user's crontab.
Schedule::command('pii:enqueue')->everyMinute()->withoutOverlapping(10);
