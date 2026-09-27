<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Installer;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;

class InstallController extends Controller
{
    private const PROVIDER_SLUGS = ['or' => 'or', 'cf' => 'cf', 'hf' => 'hf'];

    public function requirements()
    {
        return ['requirements' => Installer::requirements()];
    }

    public function database(Request $request)
    {
        $cfg = $this->dbConfig($request->all());
        try {
            $this->connect($cfg);
            $pdo = DB::connection('install_probe')->getPdo();
            $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $tables = $cfg['driver'] === 'sqlite'
                ? count(DB::connection('install_probe')->select("select name from sqlite_master where type='table'"))
                : count(DB::connection('install_probe')->select('show tables'));
        } catch (\Throwable $e) {
            abort(422, $this->cleanDbError($e->getMessage()));
        } finally {
            DB::purge('install_probe');
        }

        $label = $cfg['driver'] === 'sqlite' ? 'SQLite '.$version : (str_contains(strtolower((string) $version), 'maria') ? 'MariaDB ' : 'MySQL ').preg_replace('/-.*$/', '', (string) $version);

        return ['ok' => true, 'message' => 'Connected · '.$label.' · '.($tables ? $tables.' existing tables' : 'empty database')];
    }

    public function provider(Request $request)
    {
        $data = $request->validate(['slug' => 'required|in:or,cf,hf', 'key' => 'required|string|max:500']);
        $tpl = collect(CatalogSeeder::PROVIDERS)->firstWhere(0, self::PROVIDER_SLUGS[$data['slug']]);
        $probe = new AiProvider(['driver' => $tpl[5], 'base_url' => $tpl[6], 'name' => $tpl[1]]);
        $probe->api_key = $data['key'];

        return $probe->testConnection();
    }

    public function cron()
    {
        return Installer::cronStatus();
    }

    public function run(Request $request)
    {
        $data = $request->validate([
            'db' => 'required|array', 'app' => 'required|array', 'app.name' => 'required|string|max:80', 'app.url' => 'required|url',
            'admin.name' => 'required|string|max:120', 'admin.email' => 'required|email',
            'admin.password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
            'storage' => 'nullable|array', 'providers' => 'nullable|array', 'ffmpeg_path' => 'nullable|string|max:300',
        ]);
        @set_time_limit(300);

        $db = $this->dbConfig($data['db']);
        try {
            $this->connect($db);
            DB::connection('install_probe')->getPdo();
            DB::purge('install_probe');
        } catch (\Throwable $e) {
            abort(422, 'Database: '.$this->cleanDbError($e->getMessage()));
        }

        // 1. Point this request at the new database and migrate + seed.
        config(['database.default' => $db['driver'], "database.connections.{$db['driver']}" => array_merge(config("database.connections.{$db['driver']}"), $db)]);
        DB::purge($db['driver']);
        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        } catch (\Throwable $e) {
            abort(500, 'Migration failed: '.$e->getMessage());
        }
        try {
            Artisan::call('storage:link');
        } catch (\Throwable) {
            // Some shared hosts forbid symlinks; downloads then need a manual link.
        }

        // 2. Admin, providers, settings
        $admin = User::updateOrCreate(['email' => $data['admin']['email']], [
            'name' => $data['admin']['name'], 'password' => $data['admin']['password'], 'role' => 'admin', 'status' => 'Active',
            'credits' => 100000, 'plan_id' => Plan::orderByDesc('price')->value('id'), 'email_verified_at' => now(),
        ]);
        $connected = AiProvider::where('status', 'connected')->count();
        foreach ((array) ($data['providers'] ?? []) as $key => $apiKey) {
            if ($apiKey && isset(self::PROVIDER_SLUGS[$key])) {
                AiProvider::where('slug', self::PROVIDER_SLUGS[$key])->first()?->update(['api_key' => $apiKey, 'status' => 'connected']);
                $connected++;
            }
        }
        $mode = $data['app']['mode'] ?? 'Sell subscriptions';
        Setting::put('whitelabel', array_merge((array) Setting::get('whitelabel', []), ['appName' => $data['app']['name'], 'domain' => parse_url($data['app']['url'], PHP_URL_HOST) ?: 'localhost']));
        Setting::put('install', ['mode' => $mode, 'currency' => $data['app']['currency'] ?? 'USD', 'storage' => $storage['driver'] ?? 'Local disk', 'purchase_code' => $data['app']['purchase_code'] ?? null]);
        Setting::put('toggles', array_merge((array) Setting::get('toggles', []), ['reg' => $mode !== 'Internal team tool']));
        if (! empty($data['ffmpeg_path'])) {
            Setting::put('ffmpeg_path', $data['ffmpeg_path']);
        }
        Cache::forget('rs.settings');

        // 3. .env last: a failure above leaves the existing .env untouched (and dev servers
        //    that restart when .env changes don't cut this request short).
        $env = [
            'APP_NAME' => $data['app']['name'], 'APP_URL' => rtrim($data['app']['url'], '/'), 'APP_TIMEZONE' => $data['app']['timezone'] ?? 'UTC',
            'DB_CONNECTION' => $db['driver'],
            // Keep the current session cookie name so renaming the app doesn't log the new admin out.
            'SESSION_COOKIE' => config('session.cookie'),
        ];
        if ($db['driver'] === 'sqlite') {
            $env += ['DB_DATABASE' => $db['database']];
        } else {
            $env += ['DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'DB_PREFIX' => $db['prefix']];
        }
        $storage = $data['storage'] ?? [];
        if (($storage['driver'] ?? 'Local disk') !== 'Local disk') {
            $env += ['AWS_ACCESS_KEY_ID' => $storage['key'] ?? '', 'AWS_SECRET_ACCESS_KEY' => $storage['secret'] ?? '', 'AWS_DEFAULT_REGION' => $storage['region'] ?? 'auto',
                'AWS_BUCKET' => $storage['bucket'] ?? '', 'AWS_ENDPOINT' => $storage['endpoint'] ?? '', 'AWS_URL' => $storage['url'] ?? '', 'AWS_USE_PATH_STYLE_ENDPOINT' => 'true', 'MEDIA_DISK' => 's3'];
        }
        Installer::writeEnv($env);

        Installer::markInstalled();
        ActivityLog::record('Installation completed by '.$admin->email, 'app');
        Auth::login($admin);
        $request->session()->regenerate();

        return ['ok' => true, 'providers' => $connected, 'queue' => config('queue.default')];
    }

    private function dbConfig(array $in): array
    {
        if (($in['driver'] ?? 'mysql') === 'sqlite') {
            $path = trim((string) ($in['database'] ?? '')) ?: database_path('database.sqlite');
            if (! file_exists($path)) {
                @touch($path);
            }

            return ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true];
        }

        return ['driver' => 'mysql', 'host' => $in['host'] ?? '127.0.0.1', 'port' => (string) ($in['port'] ?? '3306'), 'database' => $in['database'] ?? '',
            'username' => $in['username'] ?? '', 'password' => $in['password'] ?? '', 'prefix' => $in['prefix'] ?? '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
    }

    private function connect(array $cfg): void
    {
        config(['database.connections.install_probe' => $cfg]);
        DB::purge('install_probe');
    }

    private function cleanDbError(string $msg): string
    {
        return trim(preg_replace('/\s*\(Connection:.*$/s', '', preg_replace('/^SQLSTATE\[[^\]]+\]\s*(\[\d+\])?\s*/', '', $msg)));
    }
}
