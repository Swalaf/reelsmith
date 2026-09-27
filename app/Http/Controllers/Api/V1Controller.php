<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\StudioController;
use App\Jobs\RunWorkflow;
use App\Models\Agent;
use App\Models\CreditTransaction;
use App\Models\Project;
use App\Models\Template;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\AiGateway;
use App\Services\ScriptWriter;
use Illuminate\Http\Request;

/** Public REST API (authenticate with `Authorization: Bearer rsk_live_…`). */
class V1Controller extends Controller
{
    public function createVideo(Request $request, ScriptWriter $writer)
    {
        $data = $request->validate([
            'topic' => 'required|string|max:200', 'platform' => 'nullable|string|max:40', 'duration' => 'nullable|integer|min:5|max:600',
            'aspect_ratio' => 'nullable|in:9:16,16:9,1:1,4:5', 'tone' => 'nullable|string|max:40', 'template_id' => 'nullable|exists:templates,id',
            'render' => 'nullable|boolean',
        ]);
        $idea = ['topic' => $data['topic'], 'platform' => $data['platform'] ?? 'TikTok', 'dur' => ($data['duration'] ?? 30).'s', 'ratio' => $data['aspect_ratio'] ?? '9:16', 'tone' => $data['tone'] ?? 'Friendly'];
        $out = $writer->write($idea);
        $project = $request->user()->projects()->create([
            'name' => $out['script']['title'] ?: $data['topic'], 'platform' => $idea['platform'], 'ratio' => $idea['ratio'], 'duration' => $data['duration'] ?? 30,
            'idea' => $idea, 'script' => $out['script'], 'scenes' => $out['scenes'], 'providers_used' => $out['provider'], 'template_id' => $data['template_id'] ?? null,
        ]);

        if ($request->boolean('render', true)) {
            app(StudioController::class)->render($request, $project);
        }

        return response()->json($this->video($project->fresh()), 202);
    }

    public function showVideo(Request $request, string $id)
    {
        $project = Project::findOrFail((int) preg_replace('/^vid_/', '', $id));
        abort_unless($project->user_id === $request->user()->id, 404);

        return $this->video($project);
    }

    public function templates(Request $request)
    {
        return ['data' => Template::where('status', 'Published')
            ->when($request->query('platform'), fn ($q, $p) => $q->where('category', 'like', '%'.$p.'%'))
            ->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'category' => $t->category, 'duration' => $t->duration, 'aspect_ratio' => $t->ratio])];
    }

    public function scripts(Request $request, ScriptWriter $writer)
    {
        $data = $request->validate(['topic' => 'required|string|max:200', 'duration' => 'nullable|integer|min:5|max:600', 'platform' => 'nullable|string', 'tone' => 'nullable|string']);
        $out = $writer->write(['topic' => $data['topic'], 'dur' => ($data['duration'] ?? 30).'s', 'platform' => $data['platform'] ?? 'TikTok', 'ratio' => '9:16', 'tone' => $data['tone'] ?? 'Friendly']);

        return ['script' => $out['script'], 'scenes' => array_map(fn ($s) => array_intersect_key($s, array_flip(['prompt', 'narration', 'caption', 'dur'])), $out['scenes']), 'provider' => $out['provider']];
    }

    public function credits(Request $request)
    {
        $u = $request->user();

        return ['balance' => $u->credits, 'used_this_period' => (int) -$u->hasMany(CreditTransaction::class)->where('amount', '<', 0)->where('created_at', '>=', now()->startOfMonth())->sum('amount')];
    }

    private function video(Project $p): array
    {
        return ['id' => 'vid_'.$p->id, 'status' => strtolower($p->status === 'Processing' ? 'queued' : $p->status), 'title' => $p->name,
            'duration' => $p->totalSeconds(), 'aspect_ratio' => $p->ratio, 'credits_reserved' => $p->credits_used, 'url' => $p->outputUrl(), 'error' => $p->error];
    }

    /** POST /api/workflows/run {workflow_id, input} */
    public function runWorkflow(Request $request)
    {
        $data = $request->validate(['workflow_id' => 'required', 'input' => 'nullable|array']);
        $id = preg_replace('/^wf_/', '', (string) $data['workflow_id']);
        $wf = Workflow::where('user_id', $request->user()->id)->where(fn ($q) => $q->where('id', (int) $id)->orWhere('name', $data['workflow_id']))->firstOrFail();
        $run = app(PlatformController::class)->startWorkflow($wf, $data['input'] ?? [], 'API');

        return response()->json(['run_id' => 'run_'.$run->id, 'status' => 'running'], 202);
    }

    /** GET /api/jobs/{id} — vid_… (video) or run_…/job_… (workflow, image, agent runs). */
    public function job(Request $request, string $id)
    {
        if (str_starts_with($id, 'vid_')) {
            return $this->showVideo($request, $id) + ['type' => 'video'];
        }
        $run = WorkflowRun::where('user_id', $request->user()->id)->findOrFail((int) preg_replace('/^(run|job)_/', '', $id));

        return ['id' => 'run_'.$run->id, 'type' => $run->kind, 'status' => strtolower($run->status), 'output' => $run->output, 'credits' => $run->credits, 'error' => $run->error,
            'steps' => array_map(fn ($s) => ['title' => $s['title'], 'status' => $s['status'], 'meta' => $s['meta'] ?? ''], (array) $run->steps)];
    }

    /** POST /api/images/generate {prompt, count, style, aspect_ratio} */
    public function images(Request $request)
    {
        $data = $request->validate(['prompt' => 'required|string|max:2000', 'count' => 'nullable|integer|min:1|max:6', 'style' => 'nullable|string|max:60', 'aspect_ratio' => 'nullable|in:9:16,16:9,1:1,4:5']);
        $prompt = $data['prompt'].(! empty($data['style']) ? ', '.str_replace('_', ' ', $data['style']).' style' : '');
        $run = WorkflowRun::create(['user_id' => $request->user()->id, 'kind' => 'image', 'name' => 'Images · API', 'trigger' => 'API', 'status' => 'Queued', 'input' => ['topic' => $data['prompt']], 'log' => [],
            'steps' => [['id' => 1, 't' => 'aiimage', 'title' => 'Generate images', 'cfg' => ['Prompt' => $prompt, 'Count' => (string) ($data['count'] ?? 1), 'Aspect ratio' => $data['aspect_ratio'] ?? '1:1'], 'status' => 'wait', 'meta' => '', 'dur' => '—']]]);
        RunWorkflow::start($run);

        return response()->json(['job_id' => 'run_'.$run->id, 'status' => 'queued'], 202);
    }

    /** POST /api/agents/{id}/run {message} — replies synchronously. */
    public function runAgent(Request $request, string $agent)
    {
        $a = Agent::where('user_id', $request->user()->id)->where(fn ($q) => $q->where('id', (int) $agent)->orWhereRaw('lower(name) = ?', [strtolower(str_replace('-', ' ', $agent))]))->firstOrFail();

        return app(PlatformController::class)->runAgent($request, $a, app(AiGateway::class));
    }
}
