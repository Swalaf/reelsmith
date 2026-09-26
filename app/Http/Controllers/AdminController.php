<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\ApiKey;
use App\Models\CreditTransaction;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Template;
use App\Models\User;
use App\Services\Payments;
use App\Support\Branding;
use App\Support\DesignPage;
use App\Support\Ffmpeg;
use App\Support\Installer;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    private const SCREENS = ['overview', 'users', 'projects', 'videos', 'providers', 'routing', 'models', 'templates', 'media', 'credits', 'plans', 'payments', 'api', 'whitelabel', 'pages', 'settings', 'logs'];

    private const SETTING_KEYS = ['toggles', 'gateways', 'creditRules', 'routing', 'disabledModels', 'limits', 'values'];

    public function show(?string $screen = null)
    {
        $users = User::with('plan')->withCount('projects')->latest()->get();
        $projects = Project::with('user')->latest()->limit(500)->get();
        $providers = AiProvider::orderBy('priority')->get();
        $storageBytes = $projects->whereNotNull('output_path')->sum(fn ($p) => Storage::disk('public')->exists($p->output_path) ? Storage::disk('public')->size($p->output_path) : 0);
        $customers = $users->where('role', '!=', 'admin');
        $mrr = $customers->sum(fn ($u) => $u->plan?->price ?? 0);
        $cron = Installer::cronStatus();
        $ffVersion = Ffmpeg::version();
        $since30 = now()->subDays(30);

        $settings = [];
        foreach (self::SETTING_KEYS as $k) {
            $settings[$k] = Setting::get($k);
        }

        return DesignPage::make('admin', 'Admin — '.Branding::appName(), [
            'startScreen' => in_array($screen, self::SCREENS, true) ? $screen : null,
            'admin' => [
                'users' => $users->map(fn (User $u) => $this->userRow($u))->values(),
                'providers' => $providers->map->toClient()->values(),
                'apiKeys' => ApiKey::with('user')->latest()->get()->map->toClient()->values(),
                'whitelabel' => Branding::whiteLabel(),
                'settings' => array_merge($settings, ['values' => Setting::publicValues()]),
                'gatewayStatus' => Payments::status(),
                'plans' => Plan::withCount('users')->orderBy('sort')->get()->map->toClient()->values(),
                'projects' => $projects->map->toClient()->values(),
                'templates' => Template::orderBy('id')->get()->map->toClient()->values(),
                'pages' => Setting::get('pages', []),
                'payments' => Payment::with('user')->latest()->limit(200)->get()->map(fn ($p) => ['id' => $p->reference, 'user' => $p->user?->name ?? '—', 'item' => $p->item, 'gw' => $p->gateway, 'amt' => '$'.number_format($p->amount, 2), 'st' => $p->status, 'date' => $p->created_at->format('M d, Y')])->values(),
                'logs' => ActivityLog::latest('id')->limit(300)->get()->map(fn ($l) => ['t' => $l->created_at->format('Y-m-d H:i:s'), 'lvl' => $l->level, 'ch' => $l->channel, 'msg' => $l->message, 'ago' => $l->created_at->diffForHumans()])->values(),
                'kpis' => [
                    ['Total Users', number_format($users->count()), 'users', '+'.$users->where('created_at', '>=', $since30)->count().' this month', 1],
                    ['Active Users', number_format(Project::where('updated_at', '>=', $since30)->distinct('user_id')->count('user_id')), 'activity', 'created a video in 30d', 0],
                    ['Videos Generated', number_format($projects->where('status', 'Completed')->count()), 'film', '+'.$projects->where('status', 'Completed')->where('updated_at', '>=', now()->subWeek())->count().' this week', 1],
                    ['AI Generations', number_format($providers->sum('requests')), 'sparkles', 'provider requests', 0],
                    ['Credits Used', number_format(-CreditTransaction::where('amount', '<', 0)->sum('amount')), 'coins', 'of '.number_format(CreditTransaction::where('amount', '>', 0)->sum('amount')).' issued', 0],
                    ['Storage', $this->bytes($storageBytes), 'hard-drive', 'rendered videos', 0],
                    ['Revenue (MRR)', '$'.number_format($mrr, 2), 'trending-up', number_format($customers->filter(fn ($u) => ($u->plan?->price ?? 0) > 0)->count()).' paying users', 1],
                ],
                'chart' => $this->chart(),
                'usage' => $providers->sortByDesc('requests')->take(5)->values()->map(fn ($p, $i) => [$p->name, number_format($p->requests).' req', '$'.number_format($p->cost, 2), max(4, (int) round($p->requests / max(1, $providers->max('requests')) * 100)), $p->cost > 0 ? 'oklch(0.58 0.19 35)' : '#17181a'])->values(),
                'revenue' => $this->revenue(),
                'revenueTotal' => '$'.number_format(Payment::where('status', 'Paid')->where('created_at', '>=', now()->startOfMonth()->subMonths(11))->sum('amount'), 2),
                'revenueDelta' => $this->revenueDelta(),
                'system' => array_values(array_filter([
                    ['Queue', config('queue.default').' · '.$cron['queue'], $cron['queueOk'] ? 'ok' : 'warn'],
                    ['Render queue', $projects->where('status', 'Processing')->count().' active', 'ok'],
                    ['FFmpeg', $ffVersion ?: 'not installed', $ffVersion ? 'ok' : 'warn'],
                    ['Cron', $cron['cron'], $cron['cronOk'] ? 'ok' : 'warn'],
                    ['Storage · '.config('filesystems.default'), is_writable(storage_path('app')) ? 'healthy' : 'not writable', is_writable(storage_path('app')) ? 'ok' : 'err'],
                    ...$providers->whereIn('status', ['rate', 'error'])->map(fn ($p) => [$p->name, $p->last_test ?: $p->status, $p->status === 'rate' ? 'warn' : 'err'])->values()->all(),
                ])),
                'creditKpis' => [
                    ['Credits issued (30d)', number_format(CreditTransaction::where('amount', '>', 0)->where('created_at', '>=', $since30)->sum('amount')), 'across all plans'],
                    ['Credits used (30d)', number_format(-CreditTransaction::where('amount', '<', 0)->where('created_at', '>=', $since30)->sum('amount')), 'renders and generations'],
                    ['Revenue (30d)', '$'.number_format(Payment::where('status', 'Paid')->where('created_at', '>=', $since30)->sum('amount'), 2), 'paid orders'],
                    ['AI cost to you', '$'.number_format($providers->sum('cost'), 2), 'tracked provider spend', 'oklch(0.45 0.12 150)'],
                ],
                'adjustments' => CreditTransaction::with('user')->where('reason', 'not like', 'Render project%')->latest()->limit(6)->get()->map(fn ($t) => [$t->user?->name ?? '—', $t->reason, ($t->amount >= 0 ? '+' : '−').number_format(abs($t->amount))])->values(),
                'health' => [
                    'set_storage_connection' => [is_writable(storage_path('app')) ? 'Writable · '.Installer::freeSpace().' free' : 'Not writable', is_writable(storage_path('app'))],
                    'set_queue_workers_workers' => [$cron['queue'], $cron['queueOk']],
                    'set_queue_workers_failed_jobs_24h_' => [($f = $this->failedJobs()).' failed', $f === 0],
                    'set_cron_last_run' => [$cron['cron'], $cron['cronOk']],
                    'set_cron_command' => null,
                ],
            ],
        ]);
    }

    // ---- Users -------------------------------------------------------------

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate(['status' => 'required|in:Active,Pending,Suspended']);
        abort_if($user->id === $request->user()->id && $data['status'] === 'Suspended', 422, "You can't suspend yourself.");
        $user->update($data);
        ActivityLog::record($user->name.' → '.$data['status'].' by '.$request->user()->name, 'auth');

        return ['user' => $this->userRow($user->fresh())];
    }

    public function deleteUser(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, "You can't delete your own account here.");
        foreach ($user->projects()->whereNotNull('output_path')->pluck('output_path') as $p) {
            Storage::disk('public')->delete($p);
        }
        ActivityLog::record('User '.$user->email.' deleted by '.$request->user()->name, 'auth', 'WARNING');
        $user->delete();

        return ['ok' => true];
    }

    public function credits(Request $request, User $user)
    {
        $data = $request->validate(['mode' => 'required|in:Add,Remove,Set', 'amount' => 'required|integer|min:0|max:100000000', 'reason' => 'nullable|string|max:190']);
        $delta = match ($data['mode']) {
            'Add' => $data['amount'], 'Remove' => -min($data['amount'], $user->credits), 'Set' => $data['amount'] - $user->credits
        };
        $user->adjustCredits($delta, ($data['reason'] ?? null) ?: 'Manual adjustment by '.$request->user()->name);

        return ['user' => $this->userRow($user->fresh())];
    }

    public function impersonate(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 422, 'Admins cannot be impersonated.');
        $adminId = $request->user()->id;
        ActivityLog::record($request->user()->name.' impersonated '.$user->email, 'auth', 'WARNING');
        Auth::login($user);
        $request->session()->put('impersonator_id', $adminId);

        return ['ok' => true];
    }

    // ---- Projects ----------------------------------------------------------

    public function deleteProject(Project $project)
    {
        if ($project->output_path) {
            Storage::disk('public')->delete($project->output_path);
        }
        $project->delete();

        return ['ok' => true];
    }

    // ---- Providers ---------------------------------------------------------

    public function addProvider(Request $request)
    {
        $data = $request->validate(['preset' => 'required|string', 'api_key' => 'nullable|string|max:500', 'base_url' => 'nullable|url|max:300', 'category' => 'nullable|in:Text,Image,Video,Voice,Audio,Speech', 'models' => 'nullable|array', 'priority' => 'nullable|integer']);
        $map = ['OpenRouter' => 'or', 'Google Gemini' => 'gem', 'Groq' => 'groq', 'Cloudflare' => 'cft', 'Hugging Face' => 'hft', 'Custom API' => 'custom'];
        $tpl = collect(CatalogSeeder::PROVIDERS)->firstWhere(0, $map[$data['preset']] ?? 'custom');
        $slug = Str::slug($data['preset']).'-'.Str::lower(Str::random(4));

        $provider = AiProvider::create([
            'slug' => $slug, 'name' => $data['preset'] === 'Custom API' ? 'Custom OpenAI-compatible' : $data['preset'], 'mono' => $tpl[2], 'category' => $data['category'] ?? $tpl[3],
            'tier' => $tpl[4], 'driver' => $tpl[5], 'base_url' => $data['base_url'] ?? $tpl[6], 'model' => $data['models'][0] ?? $tpl[7], 'models' => ($data['models'] ?? null) ?: $tpl[8],
            'api_key' => $data['api_key'] ?? null, 'status' => ! empty($data['api_key']) ? 'connected' : 'off', 'priority' => $data['priority'] ?? 50,
        ]);
        ActivityLog::record('AI provider added: '.$provider->name, 'ai');

        return ['provider' => $provider->toClient()];
    }

    public function updateProvider(Request $request, AiProvider $provider)
    {
        $data = $request->validate([
            'api_key' => 'nullable|string|max:500', 'base_url' => 'nullable|string|max:300', 'model' => 'nullable|string|max:120',
            'models' => 'nullable|array', 'models.*' => 'string|max:120', 'priority' => 'nullable|integer|min:0|max:1000',
            'category' => 'nullable|in:Text,Image,Video,Voice,Audio,Speech', 'status' => 'nullable|in:connected,disabled,off',
        ]);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        if (isset($data['models']) && ! isset($data['model'])) {
            $data['model'] = $data['models'][0] ?? $provider->model;
        }
        if (($data['status'] ?? null) === 'connected' && $provider->needsKey() && ! ($data['api_key'] ?? $provider->api_key)) {
            abort(422, 'Add an API key to connect '.$provider->name.'.');
        }
        $provider->update($data);
        ActivityLog::record('AI provider updated: '.$provider->name, 'ai');

        return ['provider' => $provider->fresh()->toClient()];
    }

    public function toggleProvider(AiProvider $provider)
    {
        abort_if($provider->status === 'off', 422, 'Configure '.$provider->name.' first.');
        $provider->update(['status' => $provider->status === 'disabled' ? 'connected' : 'disabled']);

        return ['provider' => $provider->toClient()];
    }

    // ---- Templates ---------------------------------------------------------

    public function duplicateTemplate(Template $template)
    {
        $copy = $template->replicate(['uses']);
        $copy->fill(['name' => $template->name.' (copy)', 'status' => 'Draft', 'created_by' => 'Admin', 'uses' => 0])->save();

        return ['template' => $copy->toClient()];
    }

    public function deleteTemplate(Template $template)
    {
        $template->delete();

        return ['ok' => true];
    }

    // ---- Plans -------------------------------------------------------------

    public function storePlan(Request $request)
    {
        $plan = Plan::create($this->planData($request) + ['sort' => (int) Plan::max('sort') + 1]);

        return ['plan' => $plan->loadCount('users')->toClient()];
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $plan->update($this->planData($request, $plan));

        return ['plan' => $plan->loadCount('users')->toClient()];
    }

    public function deletePlan(Plan $plan)
    {
        $free = Plan::where('price', 0)->where('id', '!=', $plan->id)->orderBy('sort')->first();
        abort_if(! $free && $plan->price == 0, 422, 'Keep at least one free plan.');
        User::where('plan_id', $plan->id)->update(['plan_id' => $free?->id]);
        $plan->delete();

        return ['ok' => true];
    }

    // ---- API keys ----------------------------------------------------------

    public function createKey(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'rate' => 'nullable|integer|min:1|max:10000']);
        [$key, $plain] = ApiKey::issue($request->user(), $data['name'], $data['rate'] ?? 60);
        ActivityLog::record('API key “'.$key->name.'” created', 'auth');

        return ['key' => $key->load('user')->toClient(), 'plain' => $plain];
    }

    public function revokeKey(ApiKey $apiKey)
    {
        $apiKey->update(['revoked_at' => now()]);

        return ['key' => $apiKey->load('user')->toClient()];
    }

    // ---- Branding & settings ----------------------------------------------

    public function whitelabel(Request $request)
    {
        $data = $request->validate(['whitelabel' => 'required|array']);
        $wl = array_intersect_key($data['whitelabel'], Branding::DEFAULTS);
        Setting::put('whitelabel', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $wl));

        return ['whitelabel' => Branding::whiteLabel()];
    }

    public function settings(Request $request)
    {
        foreach (self::SETTING_KEYS as $k) {
            if ($request->has($k)) {
                $k === 'values' ? Setting::putValues((array) $request->input($k)) : Setting::put($k, $request->input($k));
            }
        }
        if ($request->has('values') && ($path = $request->input('values.set_video_ffmpeg_path'))) {
            Setting::put('ffmpeg_path', $path);
        }

        return ['ok' => true];
    }

    public function pages(Request $request)
    {
        $data = $request->validate(['slug' => 'required|string', 'title' => 'nullable|string|max:200', 'body' => 'nullable|string|max:20000', 'meta' => 'nullable|string|max:200', 'desc' => 'nullable|string|max:500']);
        $pages = array_map(function ($p) use ($data) {
            if ($p['slug'] !== $data['slug']) {
                return $p;
            }

            return array_merge($p, ['title' => ($data['meta'] ?? null) ?: ($data['title'] ?? $p['title']), 'desc' => $data['desc'] ?? $p['desc'], 'body' => $data['body'] ?? $p['body']]);
        }, (array) Setting::get('pages', []));
        Setting::put('pages', $pages);

        return ['pages' => $pages];
    }

    // ---- helpers -----------------------------------------------------------

    private function userRow(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'videos' => $u->projects_count ?? $u->projects()->count(), 'credits' => $u->credits,
            'plan' => $u->plan?->name ?? 'Free', 'status' => in_array($u->status, ['Active', 'Pending', 'Suspended'], true) ? $u->status : 'Active',
            'joined' => $u->created_at?->format('M d, Y'), 'role' => $u->role];
    }

    private function planData(Request $request, ?Plan $plan = null): array
    {
        $d = $request->validate(['name' => 'required|string|max:60', 'price' => 'required|numeric|min:0|max:100000', 'credits' => 'required|integer|min:0', 'videos' => 'nullable|string|max:30', 'storage_gb' => 'nullable|integer|min:0', 'features' => 'nullable|array', 'features.*' => 'string|max:80', 'api_access' => 'nullable|boolean']);
        $slug = Str::slug($d['name']);
        if (Plan::where('slug', $slug)->when($plan, fn ($q) => $q->where('id', '!=', $plan->id))->exists()) {
            $slug .= '-'.Str::lower(Str::random(3));
        }

        return ['name' => $d['name'], 'slug' => $slug, 'price' => $d['price'], 'credits' => $d['credits'], 'videos_label' => $d['videos'] ?: 'Unlimited',
            'storage_gb' => $d['storage_gb'] ?? 1, 'features' => $d['features'] ?? [], 'api_access' => (bool) ($d['api_access'] ?? false)];
    }

    private function chart(): array
    {
        $rows = Project::where('created_at', '>=', now()->subDays(90)->startOfDay())->get(['created_at', 'status'])->groupBy(fn ($p) => $p->created_at->format('Y-m-d'));
        $out = [];
        for ($d = now()->subDays(89)->startOfDay(); $d <= now(); $d = $d->copy()->addDay()) {
            $day = $rows->get($d->format('Y-m-d'), collect());
            $out[] = [$d->format('M j'), $day->count(), $day->where('status', 'Failed')->count()];
        }

        return $out;
    }

    private function revenue(): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $months[] = [$m->format('M')[0], (float) Payment::where('status', 'Paid')->whereBetween('created_at', [$m, $m->copy()->endOfMonth()])->sum('amount')];
        }
        $max = max(1, max(array_column($months, 1)));

        return array_map(fn ($m) => [$m[0], max(4, (int) round($m[1] / $max * 100))], $months);
    }

    private function revenueDelta(): string
    {
        $cur = (float) Payment::where('status', 'Paid')->where('created_at', '>=', now()->startOfMonth())->sum('amount');
        $prev = (float) Payment::where('status', 'Paid')->whereBetween('created_at', [now()->startOfMonth()->subMonth(), now()->startOfMonth()])->sum('amount');

        return $prev > 0 ? sprintf('%+.1f%%', ($cur - $prev) / $prev * 100) : ($cur > 0 ? 'new this month' : '');
    }

    private function failedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function bytes(int $b): string
    {
        return $b >= 1024 ** 3 ? round($b / 1024 ** 3, 2).' GB' : ($b >= 1024 ** 2 ? round($b / 1024 ** 2, 1).' MB' : round($b / 1024).' KB');
    }
}
