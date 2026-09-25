<?php

use App\Jobs\Heartbeat;
use App\Support\Installer;
use Illuminate\Support\Facades\Schedule;

// Cron heartbeat (checked by the installer and Admin → Settings) + a queued ping that
// proves a queue worker is consuming jobs.
Schedule::call(function () {
    Installer::beat('cron-heartbeat');
    Heartbeat::dispatch();
})->everyMinute()->name('reelsmith-heartbeat')->withoutOverlapping();
