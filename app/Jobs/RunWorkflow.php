<?php

namespace App\Jobs;

use App\Models\WorkflowRun;
use App\Services\Repurposer;
use App\Services\WorkflowRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunWorkflow implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        $run = WorkflowRun::with(['user', 'workflow'])->find($this->runId);
        if (! $run || ! in_array($run->status, ['Queued', 'Scheduled'], true)) {
            return;
        }
        $run->kind === 'repurpose' ? app(Repurposer::class)->execute($run) : app(WorkflowRunner::class)->execute($run);
    }

    public function failed(\Throwable $e): void
    {
        WorkflowRun::whereKey($this->runId)->whereIn('status', ['Queued', 'Running'])->update(['status' => 'Failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
    }

    /** Start a run on the queue (or right after the response when the queue is `sync`). */
    public static function start(WorkflowRun $run): void
    {
        config('queue.default') === 'sync' ? static::dispatchAfterResponse($run->id) : static::dispatch($run->id)->onQueue('render');
    }
}
