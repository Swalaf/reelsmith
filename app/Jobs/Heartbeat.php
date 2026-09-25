<?php

namespace App\Jobs;

use App\Support\Installer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class Heartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Installer::beat('queue-heartbeat');
    }
}
