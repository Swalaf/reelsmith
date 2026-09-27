<?php

namespace App\Http\Controllers;

use App\Jobs\RenderProject;
use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Services\AiGateway;
use App\Services\SceneMedia;
use App\Services\ScriptWriter;
use App\Support\Boot;
use App\Support\Branding;
use App\Support\DesignPage;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudioController extends Controller
{
    private const SCREENS = ['dashboard', 'create', 'editor', 'render', 'library', 'projects', 'templates', 'brand', 'providers', 'media', 'credits', 'usage', 'api', 'settings', 'support'];

    public function show(Request $request, ?string $screen = null)
    {
        // /studio/media and /studio/account are both a screen (HTML) and a JSON endpoint.
        if ($request->expectsJson() && $screen === 'media') {
            return $this->media($request);
        }
        if ($request->expectsJson() && $screen === 'account') {
            return app(AccountController::class)->state($request);
        }
        $user = $request->user();
        $project = $request->query('project') ? $user->projects()->find($request->query('project')) : null;

        $bytes = 0;
        foreach ($user->projects()->whereNotNull('output_path')->pluck('output_path') as $p) {
            $bytes += Storage::disk('public')->exists($p) ? Storage::disk('public')->size($p) : 0;
        }

        return DesignPage::make('studio', 'Studio — '.Branding::appName(), [
            'startScreen' => in_array($screen, self::SCREENS, true) ? $screen : null,
            'projects' => $user->projects()->latest()->limit(300)->get()->map->toClient()->values(),
            'project' => $project?->toClient(),
            'templates' => Template::where('status', 'Published')->orderBy('id')->get()->map->toClient()->values(),
            'providers' => AiProvider::orderBy('priority')->get()->map->toClient()->values(),
            'brand' => $user->brandKit(),
            'account' => AccountController::data($user),
            'setup2fa' => $request->boolean('setup2fa'),
            'stats' => ['since' => $user->created_at?->format('M Y'), 'storageMb' => round($bytes / 1024 / 1024, 1), 'storageGb' => $user->plan?->storage_gb ?? 1],
        ]);
    }

    public function platform(Request $request, ?string $screen = null)
    {
        return DesignPage::make('platform', 'AI Platform — '.Branding::appName(), ['startScreen' => $screen]);
    }

    public function project(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return ['project' => $project->toClient()];
    }

    public function store(Request $request, ScriptWriter $writer)
    {
        $this->requireVerified($request);
        $data = $request->validate([
            'idea' => 'required|array', 'idea.topic' => 'required|string|max:200', 'idea.tone' => 'nullable|string|max:40',
            'idea.platform' => 'nullable|string|max:40', 'idea.dur' => 'nullable|string|max:8', 'idea.ratio' => 'nullable|in:9:16,16:9,1:1,4:5',
            'description' => 'nullable|string|max:2000', 'audience' => 'nullable|string|max:200', 'cta' => 'nullable|string|max:200',
            'template_id' => 'nullable|exists:templates,id',
        ]);
        $idea = array_merge(['tone' => 'Friendly', 'platform' => 'TikTok', 'dur' => '30s', 'ratio' => '9:16'], $data['idea'],
            array_filter(['description' => $data['description'] ?? null, 'audience' => $data['audience'] ?? null, 'cta' => $data['cta'] ?? null]));

        $out = $writer->write($idea);
        $project = $request->user()->projects()->create([
            'name' => $out['script']['title'] ?: $idea['topic'], 'status' => 'Draft', 'platform' => $idea['platform'], 'ratio' => $idea['ratio'],
            'duration' => (int) $idea['dur'] ?: 30, 'idea' => $idea, 'script' => $out['script'], 'scenes' => $out['scenes'],
            'providers_used' => $out['provider'], 'template_id' => $data['template_id'] ?? null,
        ]);
        if ($project->template_id) {
            Template::whereKey($project->template_id)->increment('uses');
        }
        ActivityLog::record('Script generated for project #'.$project->id.' via '.$out['provider'], 'ai');

        return ['project' => $project->toClient()];
    }

    public function regenerate(Request $request, Project $project, ScriptWriter $writer)
    {
        $this->authorizeProject($request, $project);
        $idea = array_merge($project->idea ?? [], (array) $request->input('idea', []));
        $out = $writer->write($idea);
        $project->update(['idea' => $idea, 'script' => $out['script'], 'scenes' => $out['scenes'], 'providers_used' => $out['provider']]);

        return ['project' => $project->toClient()];
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $data = $request->validate([
            'idea' => 'nullable|array', 'script' => 'nullable|array', 'scenes' => 'nullable|array|max:40',
            'scenes.*.dur' => 'nullable|integer|min:1|max:60', 'captions' => 'nullable|array', 'voice' => 'nullable|string|max:40',
            'music' => 'nullable|string|max:60',
        ]);
        // validate() keeps only keys that have rules; take the (validated) arrays whole.
        foreach (['idea', 'script', 'scenes', 'captions'] as $k) {
            if ($request->has($k)) {
                $data[$k] = $request->input($k);
            }
        }
        if (isset($data['scenes'])) {
            $data['scenes'] = array_values(array_filter($data['scenes'], fn ($s) => is_array($s) && isset($s['id'])));
            $data['scenes'] = $this->mergeScenes($project, $data['scenes']);
        }
        if (isset($data['script']['title']) && $data['script']['title'] !== '') {
            $data['name'] = mb_substr($data['script']['title'], 0, 190);
        }
        if (isset($data['idea']['platform'])) {
            $data['platform'] = $data['idea']['platform'];
        }
        if (isset($data['idea']['ratio'])) {
            $data['ratio'] = $data['idea']['ratio'];
        }
        $project->update($data);

        return ['project' => $project->fresh()->toClient()];
    }

    public function render(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        abort_if($project->status === 'Processing', 409, 'This video is already rendering.');
        $this->requireVerified($request);

        $user = $request->user();
        $cost = $project->renderCost();
        abort_if($user->credits < $cost, 422, "Insufficient credits: this render needs {$cost}, you have {$user->credits}.");

        $user->adjustCredits(-$cost, 'Render project #'.$project->id);
        $voice = AiProvider::where('category', 'Voice')->where('status', 'connected')->orderBy('priority')->value('name');
        $project->update(['status' => 'Processing', 'credits_used' => $cost, 'render_started_at' => now(), 'error' => null,
            'providers_used' => trim(($project->providers_used ?: 'Built-in writer').($voice ? ' · '.$voice : ''))]);
        ActivityLog::record("Reserved {$cost} credits for {$user->name} (project #{$project->id})", 'credits');

        // Runs on the queue worker; with QUEUE_CONNECTION=sync it runs after the response is sent.
        config('queue.default') === 'sync' ? RenderProject::dispatchAfterResponse($project->id) : RenderProject::dispatch($project->id)->onQueue('render');

        return ['project' => $project->fresh()->toClient(), 'user' => Boot::me($user->fresh())];
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        if ($project->output_path) {
            Media::delete($project->output_path);
        }
        $project->delete();

        return ['ok' => true];
    }

    public function brand(Request $request)
    {
        $data = $request->validate(['brand' => 'required|array']);
        $allowed = array_keys(User::DEFAULT_BRAND);
        $request->user()->update(['brand' => array_intersect_key($data['brand'], array_flip($allowed))]);

        return ['brand' => $request->user()->brandKit()];
    }

    public function testProvider(AiProvider $provider)
    {
        $result = $provider->testConnection();
        $status = $provider->status;
        if ($status !== 'disabled' && $provider->needsKey() && $provider->api_key) {
            $status = $result['ok'] ? 'connected' : (str_starts_with($result['message'], '429') ? 'rate' : 'error');
        }
        $provider->update(['last_test' => $result['message'].' · '.now()->format('H:i'), 'status' => $status]);
        ActivityLog::record($provider->name.' test: '.$result['message'], 'ai', $result['ok'] ? 'INFO' : 'WARNING');

        return ['result' => $result, 'provider' => $provider->fresh()->toClient()];
    }

    /** Generate (or regenerate) one scene's visual with the connected Image/Video providers. */
    public function sceneVisual(Request $request, Project $project, int $scene, SceneMedia $media)
    {
        $this->authorizeProject($request, $project);
        $data = $request->validate(['src' => 'nullable|in:AI Image,AI Video', 'prompt' => 'nullable|string|max:2000', 'provider' => 'nullable|string|max:60', 'model' => 'nullable|string|max:120']);
        [$i, $s] = $this->scene($project, $scene);
        $s['src'] = $data['src'] ?? ($s['src'] ?? 'AI Image');
        if (! empty($data['prompt'])) {
            $s['prompt'] = $data['prompt'];
        }

        $cost = $s['src'] === 'AI Video' ? 8 : 1;
        $user = $request->user();
        abort_if($user->credits < $cost, 422, "Insufficient credits: generating this needs {$cost}.");

        @set_time_limit(0);
        if (! empty($data['provider']) && ! empty($data['model'])) {
            AiProvider::where('slug', $data['provider'])->whereJsonContains('models', $data['model'])->update(['model' => $data['model']]);
        }
        try {
            $r = $media->prefer($data['provider'] ?? null)->visual($project, $s);
        } catch (\Throwable $e) {
            abort(422, mb_substr($e->getMessage(), 0, 400));
        }
        $user->adjustCredits(-$cost, ($s['src'] === 'AI Video' ? 'AI video' : 'AI image').' · project #'.$project->id);
        $scenes = $project->scenes;
        $scenes[$i] = $r['scene'];
        $used = array_filter(array_unique(array_merge(explode(' · ', (string) $project->providers_used), [$r['provider']])));
        $project->update(['scenes' => $scenes, 'providers_used' => mb_substr(implode(' · ', $used), 0, 250)]);
        ActivityLog::record("Scene visual for project #{$project->id} via {$r['provider']}", 'ai');

        return ['project' => $project->fresh()->toClient(), 'provider' => $r['provider'], 'user' => Boot::me($user->fresh())];
    }

    /** Use an uploaded image/MP4, or a file from the user's media library, as a scene visual. */
    public function sceneUpload(Request $request, Project $project, int $scene, SceneMedia $media)
    {
        $this->authorizeProject($request, $project);
        [$i, $s] = $this->scene($project, $scene);
        if ($request->filled('media')) {
            $rel = (string) $request->input('media');
            abort_unless($this->mediaLibrary($request->user())->contains('path', $rel), 404);
            unset($s['clip']);
            $s['img'] = $rel;
            $s['src'] = 'Media Library';
            $s['v'] = 'done';
        } else {
            $request->validate(['file' => 'required|file|max:204800|mimetypes:image/png,image/jpeg,image/webp,video/mp4,video/quicktime,video/webm']);
            $s = $media->upload($project, $s, $request->file('file'));
        }
        $scenes = $project->scenes;
        $scenes[$i] = $s;
        $project->update(['scenes' => $scenes]);

        return ['project' => $project->fresh()->toClient()];
    }

    public function media(Request $request)
    {
        return ['media' => $this->mediaLibrary($request->user())->values()];
    }

    public function voicePreview(Request $request, SceneMedia $media)
    {
        $data = $request->validate(['voice' => 'required|string|in:'.implode(',', array_keys(AiGateway::VOICES))]);
        try {
            return ['url' => $media->preview($data['voice'])];
        } catch (\Throwable $e) {
            abort(422, mb_substr($e->getMessage(), 0, 300));
        }
    }

    /** Every image the user has generated or uploaded across projects. */
    private function mediaLibrary($user)
    {
        $disk = Storage::disk('public');

        $uploads = MediaAsset::where('user_id', $user->id)->where('kind', 'image')->latest()->pluck('path');

        return $uploads->merge($user->projects()->latest()->get(['id', 'scenes'])->flatMap(fn ($p) => collect($p->scenes ?? [])->pluck('img')->filter()))
            ->unique()->filter(fn ($rel) => $disk->exists($rel))->take(60)
            ->map(fn ($rel) => ['path' => $rel, 'url' => Media::url($rel)]);
    }

    /** @return array{0:int,1:array} */
    private function scene(Project $project, int $sceneId): array
    {
        foreach ($project->scenes ?? [] as $i => $s) {
            if ((int) ($s['id'] ?? 0) === $sceneId) {
                return [$i, $s];
            }
        }
        abort(404, 'Scene not found — save the project first.');
    }

    /** Client edits never carry media paths, so keep the server's files for scenes that still exist. */
    private function mergeScenes(Project $project, array $incoming): array
    {
        $existing = collect($project->scenes ?? [])->keyBy(fn ($s) => (int) ($s['id'] ?? 0));
        $keep = ['img', 'clip', 'audio', 'audioSig'];

        return array_values(array_map(function ($s) use ($existing, $keep) {
            $s = array_diff_key($s, array_flip(array_merge($keep, ['imgUrl', 'clipUrl', 'audioUrl'])));
            $old = $existing->get((int) ($s['id'] ?? 0));

            return $old ? $s + array_intersect_key($old, array_flip($keep)) : $s;
        }, $incoming));
    }

    private function requireVerified(Request $request): void
    {
        if (! $request->user()->email_verified_at && ! $request->user()->isAdmin() && AuthController::verificationRequired()) {
            abort(403, 'Please verify your email address first — check your inbox for the 6-digit code.');
        }
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id || $request->user()->isAdmin(), 404);
    }
}
