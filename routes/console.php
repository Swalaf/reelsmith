<?php

use App\Http\Controllers\PlatformController;
use App\Jobs\Heartbeat;
use App\Models\Workflow;
use App\Support\Installer;
use Illuminate\Support\Facades\Schedule;

// Cron heartbeat (checked by the installer and Admin → Settings) + a queued ping that
// proves a queue worker is consuming jobs.
Schedule::call(function () {
    Installer::beat('cron-heartbeat');
    Heartbeat::dispatch();
})->everyMinute()->name('reelsmith-heartbeat')->withoutOverlapping();

// Live workflows with a Schedule trigger.
Schedule::call(function () {
    $platform = app(PlatformController::class);
    foreach (Workflow::where('live', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->get() as $wf) {
        $input = json_decode((string) (collect($wf->nodes)->firstWhere('t', 'schedule')['cfg']['Input (JSON)'] ?? '{}'), true) ?: [];
        $platform->startWorkflow($wf, $input, 'Schedule');
        $wf->update(['next_run_at' => $platform->nextRun($wf)]);
    }
})->everyMinute()->name('reelsmith-scheduled-workflows')->withoutOverlapping();

// Copy new renders and media to cloud storage when it's configured (no-op otherwise).
Schedule::command('reelsmith:storage-sync')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
