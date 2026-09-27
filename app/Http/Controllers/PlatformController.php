<?php

namespace App\Http\Controllers;

use App\Jobs\RenderProject;
use App\Jobs\RunWorkflow;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\ApiRequestLog;
use App\Models\Character;
use App\Models\Production;
use App\Models\Project;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookEvent;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\AiGateway;
use App\Services\Repurposer;
use App\Services\Webhooks;
use App\Services\WorkflowRunner;
use App\Support\Branding;
use App\Support\DesignPage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlatformController extends Controller
{
    /** Default settings for each node type (label => value); {variables} are filled at run time. */
    public const NODE_DEFAULTS = [
        'trigger' => ['Input (JSON)' => '{"topic": "5 ways to start your morning", "email": "you@example.com"}'],
        'webhook' => ['Input (JSON)' => '{}'],
        'schedule' => ['Every' => 'day', 'At' => '09:00', 'Day' => 'Monday', 'Input (JSON)' => '{"topic": "Weekly tip"}'],
        'aitext' => ['Prompt' => 'Write a 30-second video script and 3 ad headlines about {input.topic}. Tone: friendly.', 'Output variable' => 'copy', 'Agent' => ''],
        'script' => ['Topic' => '{input.topic}', 'Duration' => '30s', 'Tone' => 'Friendly'],
        'aiimage' => ['Prompt' => '{input.topic}, clean studio background, soft light', 'Count' => '3', 'Aspect ratio' => '9:16'],
        'aivideo' => ['Prompt' => 'Short cinematic clip about {input.topic}', 'Duration' => '5', 'Aspect ratio' => '9:16'],
        'aiaudio' => ['Text' => '{copy}', 'Voice' => 'Theo'],
        'voice' => ['Text' => '{copy}', 'Voice' => 'Theo'],
        'scene' => ['Notes' => 'Uses the scenes from the Script step'],
        'character' => ['Name' => ''],
        'condition' => ['If' => '{copy} contains "{input.topic}"'],
        'delay' => ['Seconds' => '5'],
        'loop' => ['Notes' => 'Runs the next steps once per run'],
        'transform' => ['Set variable' => 'headline', 'Value' => '{input.topic}'],
        'render' => ['Aspect ratio' => '9:16', 'Voice' => 'Theo', 'Music' => 'Soft Focus'],
        'file' => ['Filename' => 'result.json'],
        'storage' => ['Filename' => 'result.json'],
        'http' => ['URL' => 'https://example.com/hooks/video', 'Method' => 'POST'],
        'email' => ['To' => '{input.email}', 'Subject' => 'Your new video: {input.topic}', 'Body' => "Here it is:\n{video}\n\n{copy}"],
        'social' => ['Account' => '@yourbrand', 'Caption' => '{copy}', 'Publish webhook (Zapier / Make)' => ''],
        'output' => ['Value' => '{video}'],
    ];

    private const NODE_TITLES = ['trigger' => 'Trigger', 'webhook' => 'Webhook', 'schedule' => 'Schedule', 'aitext' => 'AI Text', 'aiimage' => 'AI Image', 'aivideo' => 'AI Video', 'aiaudio' => 'AI Audio', 'voice' => 'Voice', 'script' => 'Script', 'scene' => 'Scene', 'character' => 'Character', 'condition' => 'Condition', 'delay' => 'Delay', 'loop' => 'Loop', 'transform' => 'Transform', 'render' => 'Render', 'file' => 'File', 'storage' => 'Storage', 'http' => 'HTTP Request', 'email' => 'Email', 'social' => 'Social Media', 'output' => 'Output'];

    public const TEMPLATES = [
        'Product → video ad' => [['webhook', 'New product added'], ['aitext', 'Write ad copy'], ['aiimage', 'Product images'], ['render', 'Render ad'], ['social', 'Post to Instagram']],
        'Repurpose long video' => [['trigger', 'Start'], ['aitext', 'Summarise into posts'], ['file', 'Save posts'], ['output', 'Output']],
        'Blog → narrated video' => [['webhook', 'Blog published'], ['script', 'Write script'], ['render', 'Render narrated video'], ['output', 'Output']],
        'Lead → personalised video' => [['webhook', 'Form submitted'], ['script', 'Personalised script'], ['render', 'Render'], ['email', 'Email the lead']],
        'Topic → narrated video → email' => [['trigger', 'Start with a topic'], ['script', 'Write the script'], ['render', 'Render video'], ['email', 'Send it'], ['output', 'Output']],
    ];

    private const AGENTS = [
        ['Script Writer', 'pen-line', 'Writes hooks, scripts and CTAs in your brand voice.', ['Brand kit', 'Web search'], 'You are a senior short-form scriptwriter. Write punchy hooks in the first 3 seconds. Keep sentences under 12 words. Always end with the brand CTA.', 'Script JSON'],
        ['Director', 'clapperboard', 'Turns scripts into scenes and shot lists with camera direction.', ['Scenes', 'Characters'], 'You are a film director. Break the script into scenes and shots. For each shot specify camera, movement, lens and lighting.', 'Shot list JSON'],
        ['Marketing Agent', 'target', 'Plans campaigns and writes platform-specific copy.', ['Web search', 'Brand kit'], 'Plan a 2-week campaign with daily posts.', 'Markdown'],
        ['Ad Creator', 'megaphone', 'Produces 5 ad variations per product for A/B tests.', ['Images', 'Video'], 'Create 5 distinct ad angles: problem, social proof, offer, comparison, story.', 'Ad set JSON'],
        ['Social Media Agent', 'share-2', 'Adapts content for each platform.', ['Social', 'Schedule'], "Adapt content for each platform's format and best posting time.", 'Posts JSON'],
        ['Research Agent', 'search', 'Researches topics and summarises sources.', ['Web search', 'Files'], 'Research the topic. Cite 3–5 sources.', 'Markdown'],
        ['Voice Agent', 'mic', 'Chooses voices and directs narration pacing.', ['Voices'], 'Pick the best voice for the audience and mark emphasis.', 'SSML'],
        ['Repurposing Agent', 'split', 'Finds key moments and turns long content into clips.', ['Transcripts', 'Video'], 'Find the 10 most shareable moments under 60 seconds.', 'Clips JSON'],
    ];

    // ------------------------------------------------------------------ page

    public function show(Request $request, ?string $screen = null)
    {
        $user = $request->user();
        $this->seed($user);

        return DesignPage::make('platform', 'AI Platform — '.Branding::appName(), ['startScreen' => $screen, 'platform' => $this->data($user)]);
    }

    public function state(Request $request)
    {
        return $this->data($request->user());
    }

    private function data(User $user): array
    {
        $runs = WorkflowRun::where('user_id', $user->id)->latest('id')->limit(60)->get();
        $projects = $user->projects()->where('status', 'Completed')->whereNotNull('output_path')->latest()->limit(40)->get();
        $apiKeys = $user->apiKeys()->latest()->get();

        return [
            'workflows' => Workflow::where('user_id', $user->id)->latest('updated_at')->get()->map(fn ($w) => $this->wf($w))->values(),
            'runs' => $runs->map(fn ($r) => $this->run($r))->values(),
            'characters' => Character::where('user_id', $user->id)->orderBy('id')->get()->map(fn ($c) => $c->only(['id', 'name', 'description', 'look', 'costume', 'personality', 'voice']) + ['image' => $c->image ? Storage::disk('public')->url($c->image) : null])->values(),
            'production' => $this->prod(Production::where('user_id', $user->id)->latest('id')->first()),
            'agents' => Agent::where('user_id', $user->id)->orderBy('id')->get()->map(fn ($a) => $a->only(['id', 'name', 'icon', 'description', 'model', 'tools', 'perms', 'system', 'format']))->values(),
            'webhooks' => Webhook::where('user_id', $user->id)->latest()->get()->map(fn ($h) => ['id' => $h->id, 'url' => $h->url, 'events' => $h->events, 'deliveries' => $h->deliveries, 'rate' => $h->deliveries ? round($h->successes / $h->deliveries * 100, 1).'%' : '—', 'active' => $h->active, 'secret' => $h->secret])->values(),
            'incoming' => Workflow::where('user_id', $user->id)->get()->filter(fn ($w) => collect($w->nodes)->contains('t', 'webhook'))->map(fn ($w) => ['url' => url('/hooks/in/'.$w->hook_token), 'name' => $w->name, 'live' => $w->live])->values(),
            'events' => WebhookEvent::where('user_id', $user->id)->latest('id')->limit(40)->get()->map(fn ($e) => ['t' => $e->created_at->format('H:i:s'), 'dir' => $e->direction, 'ev' => $e->event, 'code' => (string) $e->code, 'target' => $e->target])->values(),
            'reqLogs' => ApiRequestLog::where('user_id', $user->id)->latest('id')->limit(40)->with('apiKey')->get()->map(fn ($l) => ['t' => $l->created_at->format('H:i:s'), 'm' => $l->method, 'p' => $l->path, 'k' => $l->apiKey ? $l->apiKey->prefix.'…'.$l->apiKey->last4 : '—', 's' => (string) $l->status, 'l' => $l->ms.' ms'])->values(),
            'keys' => $apiKeys->map(fn ($k) => ['n' => $k->name, 'k' => $k->prefix.'••••'.$k->last4, 's' => $k->revoked_at ? 'revoked' : 'all', 'r' => number_format($k->requests), 'l' => $k->last_used_at?->diffForHumans() ?? 'never'])->values(),
            'apiStats' => [
                'requests' => ApiRequestLog::where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->count(),
                'delivered' => WebhookEvent::where('user_id', $user->id)->where('direction', 'OUT')->whereBetween('code', [200, 299])->count(),
                'errorRate' => ($total = ApiRequestLog::where('user_id', $user->id)->count()) ? round(ApiRequestLog::where('user_id', $user->id)->where('status', '>=', 400)->count() / $total * 100, 1).'%' : '0%',
                'p95' => ($ms = ApiRequestLog::where('user_id', $user->id)->latest('id')->limit(200)->pluck('ms')->sort()->values())->count() ? $ms[(int) floor(($ms->count() - 1) * 0.95)].' ms' : '—',
            ],
            'projects' => $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'dur' => $p->durationLabel(), 'ratio' => $p->ratio, 'url' => $p->outputUrl(), 'thumb' => $p->toClient()['thumb'] ?? null, 'analysis' => Repurposer::analysis($p)])->values(),
            'stats' => [
                'videos' => $user->projects()->count(), 'images' => collect($user->projects()->pluck('scenes'))->flatten(1)->whereNotNull('img')->count(),
                'audio' => collect($user->projects()->pluck('scenes'))->flatten(1)->whereNotNull('audio')->count(),
                'automations' => Workflow::where('user_id', $user->id)->where('live', true)->count(), 'runs' => WorkflowRun::where('user_id', $user->id)->count(),
                'agentTasks' => WorkflowRun::where('user_id', $user->id)->where('kind', 'agent')->count(),
                'productions' => Production::where('user_id', $user->id)->count(),
                'shots' => collect(Production::where('user_id', $user->id)->pluck('shots'))->flatten(1)->where('st', 'done')->count(),
                'renders' => $user->projects()->where('status', 'Completed')->count(),
                'apiCalls' => ApiRequestLog::where('user_id', $user->id)->count(), 'webhooks' => WebhookEvent::where('user_id', $user->id)->where('direction', 'OUT')->count(),
            ],
            'nodeDefaults' => self::NODE_DEFAULTS,
            'templates' => array_keys(self::TEMPLATES),
            'providers' => AiProvider::where('status', 'connected')->orderBy('priority')->get(['name', 'category', 'model'])->map(fn ($p) => ['n' => $p->name, 'cat' => $p->category, 'model' => $p->model])->values(),
            'hookBase' => url('/hooks/in'),
        ];
    }

    private function seed(User $user): void
    {
        if (! Agent::where('user_id', $user->id)->exists()) {
            $text = AiProvider::where('category', 'Text')->where('status', 'connected')->orderBy('priority')->value('model') ?: 'auto';
            foreach (self::AGENTS as $a) {
                Agent::create(['user_id' => $user->id, 'name' => $a[0], 'icon' => $a[1], 'description' => $a[2], 'model' => $text, 'tools' => $a[3], 'perms' => ['run' => true, 'publish' => false, 'spend' => true], 'system' => $a[4], 'format' => $a[5]]);
            }
        }
        if (! Workflow::where('user_id', $user->id)->exists()) {
            $this->makeWorkflow($user, 'Topic → narrated video → email');
        }
        if (! Production::where('user_id', $user->id)->exists()) {
            $this->newProduction($user);
        }
    }

    private function makeWorkflow(User $user, string $template, ?string $name = null): Workflow
    {
        $steps = self::TEMPLATES[$template] ?? self::TEMPLATES['Topic → narrated video → email'];
        $nodes = [];
        $edges = [];
        foreach ($steps as $i => [$t, $title]) {
            $nodes[] = ['id' => $i + 1, 't' => $t, 'title' => $title, 'sub' => $this->sub($t, self::NODE_DEFAULTS[$t] ?? []), 'x' => 30 + ($i % 4) * 270, 'y' => 40 + intdiv($i, 4) * 210, 'cfg' => self::NODE_DEFAULTS[$t] ?? []];
            if ($i > 0) {
                $edges[] = [$i, $i + 1];
            }
        }

        return Workflow::create(['user_id' => $user->id, 'name' => $name ?: $template, 'nodes' => $nodes, 'edges' => $edges, 'settings' => ['creditCap' => 120], 'hook_token' => Str::random(32)]);
    }

    private function sub(string $t, array $cfg): string
    {
        $first = collect($cfg)->reject(fn ($v) => $v === '')->first();

        return $first ? mb_substr((string) $first, 0, 38) : 'configure →';
    }

    // ------------------------------------------------------------- workflows

    public function createWorkflow(Request $request)
    {
        $data = $request->validate(['template' => 'nullable|string|max:80', 'name' => 'nullable|string|max:120']);
        $wf = $this->makeWorkflow($request->user(), $data['template'] ?? 'Topic → narrated video → email', $data['name'] ?? null);

        return ['workflow' => $this->wf($wf)];
    }

    public function updateWorkflow(Request $request, Workflow $workflow)
    {
        $this->own($request, $workflow);
        $data = $request->validate(['name' => 'nullable|string|max:120', 'nodes' => 'nullable|array|max:60', 'edges' => 'nullable|array|max:200', 'route' => 'nullable|string|max:40', 'live' => 'nullable|boolean', 'settings' => 'nullable|array']);
        foreach (['nodes', 'edges', 'settings'] as $k) {
            if ($request->has($k)) {
                $data[$k] = $request->input($k);
            }
        }
        if (isset($data['nodes'])) {
            $data['nodes'] = array_values(array_map(fn ($n) => [
                'id' => (int) $n['id'], 't' => (string) $n['t'], 'title' => mb_substr((string) ($n['title'] ?? ''), 0, 120),
                'x' => (int) ($n['x'] ?? 0), 'y' => (int) ($n['y'] ?? 0), 'cfg' => array_map('strval', (array) ($n['cfg'] ?? [])),
                'sub' => $this->sub((string) $n['t'], (array) ($n['cfg'] ?? [])),
            ], array_filter($data['nodes'], fn ($n) => isset($n['id'], $n['t'], self::NODE_TITLES[$n['t']]))));
        }
        $workflow->fill($data);
        $workflow->next_run_at = $workflow->live ? $this->nextRun($workflow) : null;
        $workflow->save();

        return ['workflow' => $this->wf($workflow)];
    }

    public function deleteWorkflow(Request $request, Workflow $workflow)
    {
        $this->own($request, $workflow);
        $workflow->delete();

        return ['ok' => true];
    }

    public function runWorkflow(Request $request, Workflow $workflow)
    {
        $this->own($request, $workflow);

        return ['run' => $this->run($this->startWorkflow($workflow, (array) $request->input('input', []), 'Test run'))];
    }

    public function startWorkflow(Workflow $wf, array $input, string $trigger): WorkflowRun
    {
        $order = WorkflowRunner::order((array) $wf->nodes, (array) $wf->edges);
        $run = WorkflowRun::create([
            'user_id' => $wf->user_id, 'workflow_id' => $wf->id, 'kind' => 'workflow', 'name' => $wf->name, 'trigger' => $trigger, 'status' => 'Queued',
            'input' => $input + ['__edges' => $wf->edges], 'log' => [],
            'steps' => array_map(fn ($n) => ['id' => $n['id'], 't' => $n['t'], 'title' => $n['title'], 'cfg' => $n['cfg'] ?? [], 'status' => 'wait', 'meta' => '', 'dur' => '—'], $order),
        ]);
        $wf->increment('runs_count');
        RunWorkflow::start($run);

        return $run;
    }

    /** Next time a live scheduled workflow should fire (Schedule node: every hour/day/week at HH:MM). */
    public function nextRun(Workflow $wf): ?Carbon
    {
        $node = collect($wf->nodes)->firstWhere('t', 'schedule');
        if (! $node) {
            return null;
        }
        $cfg = (array) ($node['cfg'] ?? []);
        [$h, $m] = array_map('intval', array_pad(explode(':', (string) ($cfg['At'] ?? '09:00')), 2, 0));
        $now = now();
        $next = match (strtolower((string) ($cfg['Every'] ?? 'day'))) {
            'hour' => $now->copy()->startOfHour()->addMinutes($m),
            'week' => $now->copy()->next((string) ($cfg['Day'] ?? 'Monday'))->setTime($h, $m),
            default => $now->copy()->setTime($h, $m),
        };
        while ($next <= $now) {
            $next = match (strtolower((string) ($cfg['Every'] ?? 'day'))) {
                'hour' => $next->addHour(), 'week' => $next->addWeek(), default => $next->addDay()
            };
        }

        return $next;
    }

    /** Public: POST /hooks/in/{token} starts a live workflow with a Webhook trigger. */
    public function incoming(Request $request, string $token)
    {
        $wf = Workflow::where('hook_token', $token)->first();
        $ok = $wf && $wf->live && collect($wf->nodes)->contains('t', 'webhook');
        if ($wf) {
            WebhookEvent::create(['user_id' => $wf->user_id, 'direction' => 'IN', 'event' => 'workflow.trigger', 'code' => $ok ? 202 : 409, 'target' => $wf->name]);
        }
        if (! $ok) {
            return response()->json(['error' => $wf ? 'workflow is paused' : 'unknown hook'], $wf ? 409 : 404);
        }
        $run = $this->startWorkflow($wf, (array) $request->json()->all(), 'Webhook');

        return response()->json(['run_id' => 'run_'.$run->id, 'status' => 'queued'], 202);
    }

    // ------------------------------------------------------------------ runs

    public function showRun(Request $request, WorkflowRun $run)
    {
        $this->own($request, $run);

        return ['run' => $this->run($run)];
    }

    public function retryRun(Request $request, WorkflowRun $run)
    {
        $this->own($request, $run);
        $copy = $run->replicate(['started_at', 'finished_at', 'error', 'output', 'credits']);
        $copy->fill(['status' => 'Queued', 'trigger' => 'Retry of run_'.$run->id, 'log' => [],
            'steps' => array_map(fn ($s) => array_merge($s, ['status' => 'wait', 'meta' => '', 'dur' => '—']), (array) $run->steps)])->save();
        RunWorkflow::start($copy);

        return ['run' => $this->run($copy)];
    }

    /** Studio brief (Workspace → Animation / Advertising / Content / Audio studios). */
    public function brief(Request $request)
    {
        $data = $request->validate(['studio' => 'required|string|max:60', 'item' => 'nullable|string|max:60', 'brief' => 'required|string|min:3|max:4000', 'style' => 'nullable|string|max:60', 'agent' => 'nullable|string|max:80', 'count' => 'nullable|integer|min:1|max:6']);
        $style = $data['style'] ?? 'Cinematic';
        $count = $data['count'] ?? 3;
        $agent = ($data['agent'] ?? 'None') !== 'None' ? $data['agent'] : '';
        $item = $data['item'] ?? '';
        $nodes = match (true) {
            str_contains($data['studio'], 'Animation') => [['aitext', 'Write the visual prompt', ['Prompt' => "Write one vivid visual prompt (max 60 words) for a {$item} in {$style} style: {input.brief}", 'Output variable' => 'prompt', 'Agent' => $agent]], ['aiimage', 'Key frame', ['Prompt' => '{prompt}', 'Count' => '1', 'Aspect ratio' => '16:9']], ['aivideo', 'Animate', ['Prompt' => '{prompt}', 'Duration' => '5', 'Aspect ratio' => '16:9']]],
            str_contains($data['studio'], 'Advertising') => [['aitext', 'Write ad variations', ['Prompt' => "Write {$count} distinct {$item} variations (headline + 2-line body + CTA) for: {input.brief}", 'Output variable' => 'copy', 'Agent' => $agent ?: 'Ad Creator']], ['aiimage', 'Ad visuals', ['Prompt' => "{input.brief}, {$style} advertising photo", 'Count' => (string) $count, 'Aspect ratio' => '4:5']]],
            str_contains($data['studio'], 'Audio') => [['aitext', 'Write the narration', ['Prompt' => "Write a {$item} script (spoken words only, about 120 words) for: {input.brief}", 'Output variable' => 'copy', 'Agent' => $agent]], ['voice', 'Voiceover', ['Text' => '{copy}', 'Voice' => 'Theo']]],
            default => [['aitext', 'Write '.($item ?: 'content'), ['Prompt' => 'Write '.($item ?: 'the content').' for: {input.brief}', 'Output variable' => 'copy', 'Agent' => $agent]]],
        };
        array_unshift($nodes, ['trigger', 'Brief', ['Input (JSON)' => '{}']]);
        $nodes[] = ['output', 'Output', ['Value' => '{copy}']];
        $steps = [];
        foreach ($nodes as $i => [$t, $title, $cfg]) {
            $steps[] = ['id' => $i + 1, 't' => $t, 'title' => $title, 'cfg' => $cfg, 'status' => 'wait', 'meta' => '', 'dur' => '—'];
        }
        $run = WorkflowRun::create(['user_id' => $request->user()->id, 'kind' => 'brief', 'name' => $data['studio'].' · '.($item ?: 'brief'), 'trigger' => 'Brief',
            'status' => 'Queued', 'input' => ['brief' => $data['brief'], 'topic' => $data['brief'], 'style' => $style], 'steps' => $steps, 'log' => []]);
        RunWorkflow::start($run);

        return ['run' => $this->run($run)];
    }

    // ------------------------------------------------------------ characters

    public function saveCharacter(Request $request, ?Character $character = null)
    {
        if ($character) {
            $this->own($request, $character);
        }
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:1000', 'look' => 'nullable|string|max:300', 'costume' => 'nullable|string|max:300', 'personality' => 'nullable|string|max:300', 'voice' => 'nullable|string|max:60']);
        $character = $character ? tap($character)->update($data) : Character::create($data + ['user_id' => $request->user()->id]);

        return ['character' => $character->fresh()];
    }

    public function characterImage(Request $request, Character $character, AiGateway $ai)
    {
        $this->own($request, $character);
        $user = $request->user();
        abort_if($user->credits < 1, 422, 'Insufficient credits.');
        $rel = 'characters/'.$character->id.'-'.Str::lower(Str::random(6)).'.png';
        Storage::disk('public')->makeDirectory('characters');
        try {
            $ai->image("Character reference portrait: {$character->name}. {$character->description}. Appearance: {$character->look}. Wearing: {$character->costume}. Neutral background, consistent lighting.", '1:1', Storage::disk('public')->path($rel));
        } catch (\Throwable $e) {
            abort(422, mb_substr($e->getMessage(), 0, 300));
        }
        $user->adjustCredits(-1, 'Character reference · '.$character->name);
        if ($character->image) {
            Storage::disk('public')->delete($character->image);
        }
        $character->update(['image' => $rel]);

        return ['url' => Storage::disk('public')->url($rel)];
    }

    public function deleteCharacter(Request $request, Character $character)
    {
        $this->own($request, $character);
        $character->delete();

        return ['ok' => true];
    }

    // ------------------------------------------------------------ production

    private function newProduction(User $user): Production
    {
        $shots = [];
        foreach ([[1, 'Establishing shot', 'Pan', '24mm', 'Golden hour', 'Dusk', 'Clear'], [1, 'Wide shot', 'Static', '24mm', 'Golden hour', 'Dusk', 'Clear'], [2, 'Medium shot', 'Dolly', '35mm', 'High key', 'Day', 'Clear'], [2, 'Close-up', 'Static', '85mm', 'High key', 'Day', 'Clear'], [3, 'Tracking shot', 'Tracking', '35mm', 'Moonlight', 'Night', 'Fog']] as $i => $s) {
            $shots[] = ['id' => $i, 'sc' => $s[0], 'cam' => $s[1], 'move' => $s[2], 'lens' => $s[3], 'light' => $s[4], 'tod' => $s[5], 'wx' => $s[6], 'st' => 'none', 'p' => 0, 'dur' => '4s', 'c' => Project::COLORS[$i % 10]];
        }

        return Production::create(['user_id' => $user->id, 'title' => 'My first production', 'logline' => 'Describe your story in one or two sentences, then press Rewrite to draft the script.',
            'style' => 'Cinematic', 'settings' => ['Genre' => 'Drama · Mystery', 'Duration' => '1:00', 'Aspect ratio' => '16:9', 'Language' => 'English', 'Target platform' => 'YouTube'],
            'toggles' => ['consistency' => true, 'style' => true, 'autoShots' => true, 'sound' => false],
            'scenes' => [[1, 'Opening', 'Exterior', 'Dusk'], [2, 'The meeting', 'Interior', 'Day'], [3, 'Resolution', 'Exterior', 'Night']], 'shots' => $shots, 'screenplay' => []]);
    }

    private function prod(?Production $p): ?array
    {
        if (! $p) {
            return null;
        }
        $disk = Storage::disk('public');

        return ['id' => $p->id, 'title' => $p->title, 'logline' => $p->logline, 'style' => $p->style, 'settings' => $p->settings, 'toggles' => $p->toggles, 'scenes' => $p->scenes,
            'screenplay' => $p->screenplay, 'project' => $p->project_id ? Project::find($p->project_id)?->toClient() : null,
            'shots' => array_map(fn ($s) => $s + ['imgUrl' => ! empty($s['img']) ? $disk->url($s['img']) : null], (array) $p->shots)];
    }

    public function saveProduction(Request $request)
    {
        $p = Production::where('user_id', $request->user()->id)->latest('id')->firstOrFail();
        $request->validate(['title' => 'nullable|string|max:160', 'logline' => 'nullable|string|max:2000', 'style' => 'nullable|string|max:60', 'shots' => 'nullable|array|max:120']);
        $data = $request->only(['title', 'logline', 'style', 'settings', 'toggles']);
        if ($request->has('shots')) {
            $existing = collect($p->shots)->keyBy('id');
            $data['shots'] = array_values(array_map(fn ($s) => array_merge(array_intersect_key((array) $existing->get($s['id'] ?? -1, []), array_flip(['img', 'st', 'p'])), array_diff_key($s, array_flip(['img', 'imgUrl']))), (array) $request->input('shots')));
        }
        $p->update(array_filter($data, fn ($v) => $v !== null));

        return ['production' => $this->prod($p->fresh())];
    }

    public function generateShot(Request $request, int $shot, AiGateway $ai)
    {
        $p = Production::where('user_id', $request->user()->id)->latest('id')->firstOrFail();
        $shots = (array) $p->shots;
        $i = collect($shots)->search(fn ($s) => (int) $s['id'] === $shot);
        abort_if($i === false, 404);
        $user = $request->user();
        abort_if($user->credits < 1, 422, 'Insufficient credits.');
        $s = $shots[$i];
        $chars = Character::where('user_id', $user->id)->get()->map(fn ($c) => "{$c->name} ({$c->look}; {$c->costume})")->implode('; ');
        $scene = collect($p->scenes)->first(fn ($sc) => (int) $sc[0] === (int) $s['sc']);
        $prompt = mb_strtolower("{$s['cam']}, {$s['move']} camera, {$s['lens']} lens, {$s['light']}, {$s['tod']}, {$s['wx']}").'. Scene: '.($scene[1] ?? '').' at '.($scene[2] ?? '').'. Story: '.$p->logline
            .($chars && ($p->toggles['consistency'] ?? true) ? '. Characters: '.$chars : '').'. Style: '.$p->style.', shallow depth of field, film still.';
        $rel = 'productions/'.$p->id.'/shot-'.$shot.'-'.Str::lower(Str::random(5)).'.png';
        Storage::disk('public')->makeDirectory('productions/'.$p->id);
        @set_time_limit(0);
        try {
            $ai->image($prompt, ($p->settings['Aspect ratio'] ?? '16:9') === '9:16' ? '9:16' : '16:9', Storage::disk('public')->path($rel));
        } catch (\Throwable $e) {
            abort(422, mb_substr($e->getMessage(), 0, 300));
        }
        $user->adjustCredits(-1, 'Cinematic shot · '.$p->title);
        if (! empty($s['img'])) {
            Storage::disk('public')->delete($s['img']);
        }
        $shots[$i] = array_merge($s, ['img' => $rel, 'st' => 'done', 'p' => 100]);
        $p->update(['shots' => $shots]);

        return ['production' => $this->prod($p->fresh())];
    }

    /** Assemble cut: every generated shot becomes a scene of a normal project, rendered with FFmpeg. */
    public function assemble(Request $request)
    {
        $user = $request->user();
        $p = Production::where('user_id', $user->id)->latest('id')->firstOrFail();
        $shots = collect($p->shots)->filter(fn ($s) => ! empty($s['img']))->values();
        abort_if($shots->isEmpty(), 422, 'Generate at least one shot first.');
        $lines = collect($p->screenplay)->filter(fn ($l) => ($l['kind'] ?? '') === 'action' || ($l['kind'] ?? '') === 'dialogue')->pluck('t')->values();
        $scenes = $shots->map(fn ($s, $i) => ['id' => $i + 1, 'prompt' => $s['cam'], 'narration' => $lines[$i] ?? '', 'caption' => '', 'dur' => (int) $s['dur'] ?: 4, 'src' => 'Upload', 'img' => $s['img'], 'tr' => 'Fade', 'c' => $s['c'] ?? '#23343f'])->all();
        $ratio = in_array($p->settings['Aspect ratio'] ?? '16:9', ['9:16', '1:1'], true) ? $p->settings['Aspect ratio'] : '16:9';
        $project = $user->projects()->create(['name' => $p->title, 'status' => 'Draft', 'ratio' => $ratio, 'platform' => 'Cinematic', 'scenes' => $scenes, 'voice' => 'Theo', 'music' => 'Night Drive', 'captions' => ['anim' => $lines->isEmpty() ? 'Off' : 'Karaoke', 'font' => 'DM Sans', 'size' => 36]]);
        $cost = $project->renderCost();
        abort_if($user->credits < $cost, 422, "Insufficient credits: the cut needs {$cost}.");
        $user->adjustCredits(-$cost, 'Assemble cut · '.$p->title);
        $project->update(['status' => 'Processing', 'credits_used' => $cost, 'render_started_at' => now()]);
        $p->update(['project_id' => $project->id]);
        config('queue.default') === 'sync' ? RenderProject::dispatchAfterResponse($project->id) : RenderProject::dispatch($project->id)->onQueue('render');

        return ['project' => $project->toClient()];
    }

    public function rewrite(Request $request, AiGateway $ai)
    {
        $p = Production::where('user_id', $request->user()->id)->latest('id')->firstOrFail();
        $chars = Character::where('user_id', $request->user()->id)->pluck('name')->implode(', ');
        try {
            $raw = $ai->text('You are a screenwriter. Reply with JSON only.', 'Write a short screenplay ('.count((array) $p->scenes).' scenes) for: '.$p->logline.($chars ? ". Characters: {$chars}." : '')
                .' Genre: '.($p->settings['Genre'] ?? 'Drama').'. JSON: {"lines":[{"kind":"heading|action|character|paren|dialogue","t":string}]}')['result'];
            $lines = json_decode(preg_match('/\{.*\}/s', $raw, $m) ? $m[0] : $raw, true)['lines'] ?? null;
        } catch (\Throwable $e) {
            abort(422, mb_substr($e->getMessage(), 0, 300));
        }
        abort_unless(is_array($lines) && $lines, 422, 'The AI reply could not be read — try again.');
        $p->update(['screenplay' => array_values(array_filter($lines, fn ($l) => isset($l['kind'], $l['t'])))]);

        return ['production' => $this->prod($p->fresh())];
    }

    // ---------------------------------------------------------------- agents

    public function saveAgent(Request $request, ?Agent $agent = null)
    {
        if ($agent) {
            $this->own($request, $agent);
        }
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:300', 'model' => 'nullable|string|max:120', 'tools' => 'nullable|array', 'perms' => 'nullable|array', 'system' => 'nullable|string|max:8000', 'format' => 'nullable|string|max:40', 'icon' => 'nullable|string|max:40']);
        $agent = $agent ? tap($agent)->update($data) : Agent::create($data + ['user_id' => $request->user()->id]);

        return ['agent' => $agent->fresh()->only(['id', 'name', 'icon', 'description', 'model', 'tools', 'perms', 'system', 'format'])];
    }

    public function deleteAgent(Request $request, Agent $agent)
    {
        $this->own($request, $agent);
        $agent->delete();

        return ['ok' => true];
    }

    public function runAgent(Request $request, Agent $agent, AiGateway $ai)
    {
        $this->own($request, $agent);
        $data = $request->validate(['message' => 'required|string|max:8000']);
        $user = $request->user();
        abort_if($user->credits < 1, 422, 'Insufficient credits.');
        $t = microtime(true);
        try {
            $r = $ai->text(trim($agent->system."\nReply in this format: ".$agent->format), $data['message']);
        } catch (\Throwable $e) {
            abort(422, mb_substr($e->getMessage(), 0, 300));
        }
        $user->adjustCredits(-1, 'Agent · '.$agent->name);
        $run = WorkflowRun::create(['user_id' => $user->id, 'kind' => 'agent', 'name' => $agent->name, 'trigger' => 'Agent', 'status' => 'Completed', 'credits' => 1,
            'input' => ['message' => $data['message']], 'output' => ['text' => $r['result']], 'started_at' => now(), 'finished_at' => now(),
            'steps' => [['id' => 1, 't' => 'aitext', 'title' => $agent->name, 'status' => 'done', 'meta' => $r['provider'], 'dur' => round(microtime(true) - $t, 1).'s', 'credits' => 1]], 'log' => []]);

        return ['reply' => $r['result'], 'provider' => $r['provider'], 'run' => $this->run($run)];
    }

    // ------------------------------------------------------------- repurpose

    public function repurpose(Request $request)
    {
        $data = $request->validate(['project_id' => 'required|integer', 'outputs' => 'required|array|min:1', 'outputs.*' => 'in:'.implode(',', array_keys(Repurposer::OUTPUTS))]);
        $project = $request->user()->projects()->where('status', 'Completed')->findOrFail($data['project_id']);
        $run = WorkflowRun::create(['user_id' => $request->user()->id, 'kind' => 'repurpose', 'name' => 'Repurpose · '.$project->name, 'trigger' => 'Repurpose', 'status' => 'Queued',
            'input' => ['project_id' => $project->id], 'log' => [],
            'steps' => array_map(fn ($k) => ['id' => $k, 'key' => $k, 't' => 'output', 'title' => Repurposer::OUTPUTS[$k], 'status' => 'wait', 'meta' => '', 'dur' => '—'], $data['outputs'])]);
        RunWorkflow::start($run);

        return ['run' => $this->run($run)];
    }

    // -------------------------------------------------------------- webhooks

    public function addWebhook(Request $request)
    {
        $data = $request->validate(['url' => 'required|url|max:500', 'events' => 'nullable|array', 'events.*' => 'in:*,'.implode(',', Webhooks::EVENTS)]);
        $hook = Webhook::create(['user_id' => $request->user()->id, 'url' => $data['url'], 'events' => $data['events'] ?: ['video.completed', 'run.completed', 'run.failed'], 'secret' => Str::random(40)]);

        return ['webhook' => ['id' => $hook->id, 'secret' => $hook->secret]];
    }

    public function testWebhook(Request $request, Webhook $webhook)
    {
        $this->own($request, $webhook);
        $code = Webhooks::deliver($webhook, 'ping', ['message' => 'Test delivery from '.Branding::appName()]);

        return ['code' => $code];
    }

    public function deleteWebhook(Request $request, Webhook $webhook)
    {
        $this->own($request, $webhook);
        $webhook->delete();

        return ['ok' => true];
    }

    // --------------------------------------------------------------- helpers

    private function own(Request $request, $model): void
    {
        abort_unless((int) $model->user_id === (int) $request->user()->id, 404);
    }

    private function wf(Workflow $w): array
    {
        $trigger = collect($w->nodes)->first(fn ($n) => in_array($n['t'], ['trigger', 'webhook', 'schedule'], true));
        $failing = WorkflowRun::where('workflow_id', $w->id)->latest('id')->value('status') === 'Failed';

        return ['id' => $w->id, 'name' => $w->name, 'nodes' => $w->nodes, 'edges' => $w->edges, 'route' => $w->route, 'live' => $w->live, 'settings' => $w->settings,
            'trigger' => $trigger ? ($trigger['t'] === 'webhook' ? 'Webhook' : ($trigger['t'] === 'schedule' ? 'Every '.($trigger['cfg']['Every'] ?? 'day').' '.($trigger['cfg']['At'] ?? '') : 'Manual')) : 'Manual',
            'triggerType' => $trigger['t'] ?? 'trigger', 'runs' => $w->runs_count, 'last' => $w->last_run_at?->diffForHumans() ?? 'Never',
            'status' => $failing ? 'Failing' : ($w->live ? 'Active' : 'Draft'), 'hookUrl' => url('/hooks/in/'.$w->hook_token), 'nextRun' => $w->next_run_at?->diffForHumans()];
    }

    private function run(WorkflowRun $r): array
    {
        $dur = $r->started_at ? ($r->finished_at ?? now())->diffInSeconds($r->started_at, true) : 0;

        return ['id' => $r->id, 'code' => 'run_'.$r->id, 'kind' => $r->kind, 'wf' => $r->name, 'workflowId' => $r->workflow_id, 'status' => $r->status, 'trigger' => $r->trigger,
            'when' => $r->created_at?->diffForHumans(), 'dur' => $r->started_at ? sprintf('%d:%02d', intdiv((int) $dur, 60), (int) $dur % 60) : '—',
            'steps' => $r->steps, 'log' => $r->log, 'output' => $r->output, 'credits' => $r->credits, 'error' => $r->error];
    }
}
