<?php

namespace App\Services;

use App\Jobs\RenderProject;
use App\Models\ActivityLog;
use App\Models\Agent;
use App\Models\Character;
use App\Models\Project;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Executes a workflow run node by node (AI Platform → Workflows, studio briefs, agents).
 *
 * Nodes run in graph order starting from the trigger. Node settings can reference earlier
 * results with {variables} — e.g. {input.topic}, {copy}, {images.0}, {video}, {audio}.
 * Every step records its provider, duration and credits so the Runs screen shows exactly
 * what happened; a failing step stops the run and later steps are marked skipped.
 */
class WorkflowRunner
{
    /** Credits per AI call (renders are charged at the Studio render price). */
    public const COST = ['aitext' => 1, 'script' => 1, 'aiimage' => 1, 'aivideo' => 8, 'voice' => 1, 'aiaudio' => 1];

    private array $ctx = [];

    private WorkflowRun $run;

    public function __construct(private AiGateway $ai = new AiGateway) {}

    /** Run a prepared WorkflowRun whose `steps` hold the ordered nodes. */
    public function execute(WorkflowRun $run): void
    {
        @set_time_limit(0);
        $this->run = $run;
        $this->ctx = ['input' => (array) $run->input] + (array) $run->input;
        $run->update(['status' => 'Running', 'started_at' => now()]);
        $this->log('▶ Run started · run_'.$run->id, '#e7e7ea');

        $steps = (array) $run->steps;
        $skipFrom = null;
        $failed = null;
        $cap = (int) ($run->workflow?->settings['creditCap'] ?? 0);

        foreach ($steps as $i => $step) {
            if ($failed !== null || ($skipFrom !== null && in_array($step['id'], $skipFrom, true))) {
                $steps[$i]['status'] = 'skip';
                $steps[$i]['meta'] = $failed !== null ? 'skipped' : 'skipped · condition was false';

                continue;
            }
            $steps[$i]['status'] = 'run';
            $run->update(['steps' => $steps]);
            $t = microtime(true);
            try {
                $cost = self::COST[$step['t']] ?? 0;
                if ($cap && $run->credits + $cost > $cap) {
                    throw new \RuntimeException("credit cap of {$cap} per run reached");
                }
                [$meta, $credits, $skip] = $this->node($step) + [2 => null];
                if ($credits > 0) {
                    $run->user->adjustCredits(-$credits, 'Workflow step · run_'.$run->id);
                    $run->credits += $credits;
                }
                if ($skip) {
                    $skipFrom = array_merge($skipFrom ?? [], $skip);
                }
                $steps[$i] = array_merge($steps[$i], ['status' => 'done', 'meta' => $meta, 'dur' => round(microtime(true) - $t, 1).'s', 'credits' => $credits]);
                $this->log('✓ '.$step['title'].' — '.$meta.' · '.$steps[$i]['dur'].' · '.$credits.' cr', '#d8d9dc');
            } catch (\Throwable $e) {
                $failed = mb_substr($e->getMessage(), 0, 300);
                $steps[$i] = array_merge($steps[$i], ['status' => 'fail', 'meta' => $failed, 'dur' => round(microtime(true) - $t, 1).'s']);
                $this->log('✕ '.$step['title'].' — '.$failed, 'oklch(0.72 0.17 25)');
            }
            $run->update(['steps' => $steps, 'credits' => $run->credits]);
        }

        $secs = $run->started_at ? now()->diffInSeconds($run->started_at, true) : 0;
        $output = array_filter(['text' => $this->ctx['output'] ?? $this->ctx['copy'] ?? null, 'images' => $this->ctx['images'] ?? null,
            'video' => $this->ctx['video'] ?? null, 'audio' => $this->ctx['audio'] ?? null, 'file' => $this->ctx['file'] ?? null,
            'project_id' => $this->ctx['project_id'] ?? null]);
        $run->update(['status' => $failed ? 'Failed' : 'Completed', 'error' => $failed, 'finished_at' => now(), 'output' => $output, 'steps' => $steps]);
        $this->log($failed ? '■ Failed · '.round($secs, 1).'s' : '■ Completed · '.round($secs, 1).'s · '.$run->credits.' credits', $failed ? 'oklch(0.72 0.17 25)' : 'oklch(0.78 0.14 150)');
        $run->workflow?->forceFill(['last_run_at' => now()])->save();
        ActivityLog::record("Run #{$run->id} ({$run->name}) ".($failed ? 'failed: '.$failed : 'completed'), 'queue', $failed ? 'WARNING' : 'INFO');
        Webhooks::dispatch($run->user, $failed ? 'run.failed' : 'run.completed', ['run_id' => 'run_'.$run->id, 'name' => $run->name, 'status' => $run->status, 'output' => $output, 'error' => $failed]);
    }

    /** @return array{0:string,1:int,2?:array|null} meta, credits, ids to skip */
    private function node(array $n): array
    {
        $cfg = (array) ($n['cfg'] ?? []);
        $c = fn (string $k, string $d = '') => $this->tpl((string) ($cfg[$k] ?? $d));
        $dir = 'runs/'.$this->run->id;
        $disk = Storage::disk('public');

        switch ($n['t']) {
            case 'trigger':
            case 'webhook':
            case 'schedule':
                $json = json_decode((string) ($cfg['Input (JSON)'] ?? ''), true);
                if (is_array($json)) {
                    $this->ctx = array_merge($json, $this->ctx, ['input' => array_merge($json, (array) ($this->ctx['input'] ?? []))]);
                }

                return [$this->run->trigger.' · '.count((array) ($this->ctx['input'] ?? [])).' input fields', 0];

            case 'aitext':
                $system = 'You are a skilled marketing copywriter and video producer. Reply with the requested content only.';
                if (! empty($cfg['Agent']) && ($agent = Agent::where('user_id', $this->run->user_id)->where('name', $cfg['Agent'])->first())) {
                    $system = (string) $agent->system;
                }
                $r = $this->ai->text($system, $c('Prompt', '{input.topic}'));
                $var = Str::slug($cfg['Output variable'] ?? 'copy', '_') ?: 'copy';
                $this->ctx[$var] = trim($r['result']);

                return [$r['provider'].' · '.str_word_count($this->ctx[$var]).' words → {'.$var.'}', self::COST['aitext']];

            case 'script':
                $out = (new ScriptWriter($this->ai))->write(['topic' => $c('Topic', '{input.topic}'), 'dur' => $c('Duration', '30s'), 'tone' => $c('Tone', 'Friendly'), 'platform' => 'TikTok', 'ratio' => '9:16']);
                $this->ctx['script'] = $out['script'] + ['scenes' => $out['scenes']];
                $this->ctx['copy'] = trim(implode(' ', [$out['script']['hook'], $out['script']['body'], $out['script']['cta']]));

                return [$out['provider'].' · '.count($out['scenes']).' scenes', self::COST['script']];

            case 'scene':
                return [count($this->ctx['script']['scenes'] ?? []).' scenes planned', 0];

            case 'character':
                $ch = Character::where('user_id', $this->run->user_id)->where('name', $c('Name'))->first();
                if ($ch) {
                    $this->ctx['character'] = $ch->name.': '.$ch->look.', '.$ch->costume;
                }

                return [$ch ? 'using '.$ch->name : 'no character named "'.$c('Name').'"', 0];

            case 'aiimage':
                $count = max(1, min(6, (int) ($cfg['Count'] ?? 1)));
                $prompt = $c('Prompt', '{input.topic}').(isset($this->ctx['character']) ? '. Character: '.$this->ctx['character'] : '').(isset($this->ctx['style']) ? '. Style: '.$this->ctx['style'] : '');
                $disk->makeDirectory($dir);
                $urls = [];
                $paths = [];
                $provider = '';
                for ($k = 0; $k < $count; $k++) {
                    $rel = $dir.'/image-'.$n['id'].'-'.$k.'.png';
                    $provider = $this->ai->image($prompt.($count > 1 ? ' (variation '.($k + 1).')' : ''), $c('Aspect ratio', '9:16'), $disk->path($rel));
                    $urls[] = $disk->url($rel);
                    $paths[] = $rel;
                }
                $this->ctx['images'] = array_merge($this->ctx['images'] ?? [], $urls);
                $this->ctx['_images'] = array_merge($this->ctx['_images'] ?? [], $paths);
                Webhooks::dispatch($this->run->user, 'image.completed', ['run_id' => 'run_'.$this->run->id, 'images' => $urls]);

                return [$provider.' · '.$count.' image'.($count > 1 ? 's' : ''), self::COST['aiimage'] * $count];

            case 'aivideo':
                $disk->makeDirectory($dir);
                $rel = $dir.'/clip-'.$n['id'].'.mp4';
                $first = $this->ctx['_images'][0] ?? null;
                $provider = $this->ai->video($c('Prompt', '{input.topic}'), $c('Aspect ratio', '9:16'), (int) $c('Duration', '5'), $first ? $disk->path($first) : null, $disk->path($rel));
                $this->ctx['video'] = $disk->url($rel);

                return [$provider.' · clip', self::COST['aivideo']];

            case 'voice':
            case 'aiaudio':
                $disk->makeDirectory($dir);
                $rel = $dir.'/audio-'.$n['id'].'.mp3';
                $voice = explode(' ', trim($c('Voice', 'Theo')))[0] ?: 'Theo';
                $provider = $this->ai->speech(mb_substr($c('Text', '{copy}'), 0, 4000), $voice, $disk->path($rel));
                $this->ctx['audio'] = $disk->url($rel);

                return [$provider.' · '.$voice, self::COST['voice']];

            case 'render':
                return $this->render($cfg);

            case 'condition':
                $ok = $this->condition((string) ($cfg['If'] ?? ''));

                return [$ok ? 'true → continue' : 'false → skipping the branch', 0, $ok ? null : $this->descendants($n['id'])];

            case 'delay':
                $secs = max(0, min(30, (int) ($cfg['Seconds'] ?? 1)));
                sleep($secs);

                return ['waited '.$secs.'s', 0];

            case 'loop':
                return ['pass-through', 0];

            case 'transform':
                $var = Str::slug($cfg['Set variable'] ?? 'value', '_') ?: 'value';
                $this->ctx[$var] = $c('Value');

                return ['{'.$var.'} = '.mb_substr($this->ctx[$var], 0, 60), 0];

            case 'file':
            case 'storage':
                $disk->makeDirectory($dir);
                $rel = $dir.'/'.(Str::slug(pathinfo($cfg['Filename'] ?? 'output', PATHINFO_FILENAME)) ?: 'output').'.json';
                $disk->put($rel, json_encode($this->publicCtx(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->ctx['file'] = $disk->url($rel);

                return ['saved '.basename($rel), 0];

            case 'http':
                $url = $c('URL');
                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new \RuntimeException('set a valid URL');
                }
                $res = Http::timeout(20)->send(strtoupper($c('Method', 'POST')), $url, ['json' => $this->publicCtx()]);
                $this->ctx['http'] = ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 2000)];
                if ($res->failed()) {
                    throw new \RuntimeException('HTTP '.$res->status().' from '.parse_url($url, PHP_URL_HOST));
                }

                return ['HTTP '.$res->status().' · '.parse_url($url, PHP_URL_HOST), 0];

            case 'email':
                $to = $c('To', '{input.email}');
                if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('"'.$to.'" is not an email address');
                }
                $body = $c('Body', "{copy}\n\n{video}");
                Mail::raw($body, fn ($m) => $m->to($to)->subject($c('Subject', 'Your new video')));

                return ['sent to '.$to, 0];

            case 'social':
                $url = (string) ($cfg['Publish webhook (Zapier / Make)'] ?? '');
                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new \RuntimeException('connect a Zapier/Make webhook URL that posts to your social account');
                }
                $res = Http::timeout(20)->post($url, ['caption' => $c('Caption', '{copy}'), 'account' => $c('Account'), 'video' => $this->ctx['video'] ?? null, 'images' => $this->ctx['images'] ?? []]);
                if ($res->failed()) {
                    throw new \RuntimeException('publish webhook returned HTTP '.$res->status());
                }

                return ['handed to '.parse_url($url, PHP_URL_HOST), 0];

            case 'output':
                $this->ctx['output'] = $c('Value', '{copy}');

                return ['output set', 0];
        }

        return ['no action', 0];
    }

    private function render(array $cfg): array
    {
        $user = $this->run->user;
        $ratio = $cfg['Aspect ratio'] ?? '9:16';
        $scenes = [];
        if (! empty($this->ctx['script']['scenes'])) {
            $scenes = $this->ctx['script']['scenes'];
        } else {
            $images = $this->ctx['_images'] ?? [];
            $sentences = preg_split('/(?<=[.!?])\s+/', trim((string) ($this->ctx['copy'] ?? $this->ctx['input']['topic'] ?? ''))) ?: [''];
            $n = max(1, count($images), min(6, count($sentences)));
            for ($i = 0; $i < $n; $i++) {
                $scenes[] = ['id' => $i + 1, 'prompt' => $this->ctx['input']['topic'] ?? 'scene', 'narration' => $sentences[$i] ?? '', 'caption' => '', 'dur' => 4,
                    'src' => isset($images[$i]) ? 'Upload' : 'AI Image', 'img' => $images[$i] ?? null, 'tr' => 'Fade', 'c' => Project::COLORS[$i % 10]];
            }
        }
        $project = $user->projects()->create([
            'name' => mb_substr((string) ($this->ctx['script']['title'] ?? $this->ctx['input']['topic'] ?? $this->run->name), 0, 190),
            'status' => 'Processing', 'ratio' => $ratio, 'platform' => 'Workflow', 'voice' => $cfg['Voice'] ?? 'Theo',
            'music' => $cfg['Music'] ?? 'Soft Focus', 'scenes' => array_values(array_map(fn ($s) => array_filter($s, fn ($v) => $v !== null), $scenes)),
            'script' => $this->ctx['script'] ?? null, 'idea' => ['topic' => $this->ctx['input']['topic'] ?? $this->run->name, 'ratio' => $ratio],
        ]);
        $cost = $project->renderCost();
        if ($user->credits < $cost) {
            $project->update(['status' => 'Failed', 'error' => 'Insufficient credits']);
            throw new \RuntimeException("insufficient credits for render (needs {$cost})");
        }
        $user->adjustCredits(-$cost, 'Render project #'.$project->id.' (workflow)');
        $project->update(['credits_used' => $cost, 'render_started_at' => now()]);
        app()->call([new RenderProject($project->id), 'handle']);
        $project->refresh();
        if ($project->status !== 'Completed') {
            throw new \RuntimeException('render failed: '.$project->error);
        }
        $this->ctx['video'] = $project->outputUrl();
        $this->ctx['project_id'] = $project->id;
        $this->run->credits += 0; // render credits were charged to the project

        return ['project #'.$project->id.' · '.$project->durationLabel().' · '.($project->render_stage ?: 'MP4'), 0];
    }

    private function condition(string $expr): bool
    {
        $expr = trim($expr);
        if (preg_match('/^(.*?)\s+(not contains|contains|==|!=)\s+"?(.*?)"?$/i', $expr, $m)) {
            $left = mb_strtolower($this->tpl($m[1]));
            $right = mb_strtolower($this->tpl($m[3]));

            return match (strtolower($m[2])) {
                'contains' => str_contains($left, $right),
                'not contains' => ! str_contains($left, $right),
                '==' => trim($left) === trim($right),
                '!=' => trim($left) !== trim($right),
            };
        }

        return trim($this->tpl($expr)) !== '';
    }

    private function descendants(int $id): array
    {
        $edges = (array) ($this->run->input['__edges'] ?? []);
        $out = [];
        $queue = [$id];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($edges as $e) {
                if ((int) $e[0] === $cur && ! in_array((int) $e[1], $out, true)) {
                    $out[] = (int) $e[1];
                    $queue[] = (int) $e[1];
                }
            }
        }

        return $out;
    }

    /** Replace {path.to.value} with values from the run context. */
    public function tpl(string $s): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function ($m) {
            $v = data_get($this->ctx, $m[1]);

            return is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : (string) ($v ?? '');
        }, $s);
    }

    private function publicCtx(): array
    {
        return array_filter($this->ctx, fn ($k) => ! str_starts_with((string) $k, '_') && $k !== '__edges', ARRAY_FILTER_USE_KEY);
    }

    private function log(string $m, string $c): void
    {
        $started = $this->run->started_at ?? now();
        $log = (array) $this->run->log;
        $log[] = ['t' => str_pad(number_format(now()->diffInMilliseconds($started, true) / 1000, 1), 4, '0', STR_PAD_LEFT), 'm' => $m, 'c' => $c];
        $this->run->update(['log' => $log]);
    }

    /**
     * Order a workflow's nodes for execution: breadth-first from trigger nodes along the edges,
     * then any unconnected nodes.
     */
    public static function order(array $nodes, array $edges): array
    {
        $byId = collect($nodes)->keyBy('id');
        $incoming = [];
        foreach ($edges as $e) {
            $incoming[(int) $e[1]] = true;
        }
        $starts = collect($nodes)->filter(fn ($n) => in_array($n['t'], ['trigger', 'webhook', 'schedule'], true) || ! isset($incoming[$n['id']]))->pluck('id')->all();
        $seen = [];
        $queue = $starts;
        while ($queue) {
            $id = (int) array_shift($queue);
            if (isset($seen[$id]) || ! $byId->has($id)) {
                continue;
            }
            $seen[$id] = true;
            foreach ($edges as $e) {
                if ((int) $e[0] === $id) {
                    $queue[] = (int) $e[1];
                }
            }
        }
        foreach ($byId->keys() as $id) {
            $seen[(int) $id] ??= true;
        }

        return array_map(fn ($id) => $byId[$id], array_keys($seen));
    }
}
