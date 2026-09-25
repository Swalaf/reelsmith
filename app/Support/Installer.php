<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Installer
{
    public static function flagPath(): string
    {
        return storage_path('app/installed');
    }

    public static function installed(): bool
    {
        // Tests (and ops tooling) can pin the state without touching the flag file.
        if (($forced = config('app.installed')) !== null) {
            return (bool) $forced;
        }

        return file_exists(static::flagPath());
    }

    public static function markInstalled(): void
    {
        file_put_contents(static::flagPath(), now()->toIso8601String());
    }

    /** @return list<array{key: string, label: string, value: string, ok: bool}> */
    public static function requirements(): array
    {
        $ext = ['pdo', 'mbstring', 'curl', 'openssl', 'tokenizer', 'xml', 'ctype', 'fileinfo'];
        $missing = array_values(array_filter($ext, fn ($e) => ! extension_loaded($e)));
        $drivers = array_values(array_intersect(['mysql', 'sqlite'], \PDO::getAvailableDrivers()));
        $mem = ini_get('memory_limit');
        $memBytes = static::bytes($mem);
        $exec = (int) ini_get('max_execution_time');
        $ff = Ffmpeg::version();
        $cron = static::cronStatus();

        return [
            ['key' => 'php', 'label' => 'PHP ≥ 8.3', 'value' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.3.0', '>=')],
            ['key' => 'pdo', 'label' => 'MySQL / SQLite driver', 'value' => $drivers ? implode(', ', $drivers) : 'none', 'ok' => (bool) $drivers],
            ['key' => 'ext', 'label' => 'PHP extensions', 'value' => $missing ? 'missing: '.implode(', ', $missing) : implode(', ', $ext), 'ok' => ! $missing],
            ['key' => 'openssl', 'label' => 'OpenSSL', 'value' => defined('OPENSSL_VERSION_TEXT') ? preg_replace('/^OpenSSL\s+/', '', OPENSSL_VERSION_TEXT) : 'missing', 'ok' => extension_loaded('openssl')],
            ['key' => 'storage', 'label' => 'Storage permissions', 'value' => is_writable(storage_path()) ? 'writable' : 'storage/ not writable', 'ok' => is_writable(storage_path())],
            ['key' => 'cache', 'label' => 'bootstrap/cache writable', 'value' => is_writable(base_path('bootstrap/cache')) ? 'writable' : 'not writable', 'ok' => is_writable(base_path('bootstrap/cache'))],
            ['key' => 'env', 'label' => '.env writable', 'value' => static::envWritable() ? 'writable' : 'not writable', 'ok' => static::envWritable()],
            ['key' => 'ffmpeg', 'label' => 'FFmpeg (recommended)', 'value' => $ff ?: 'not found — renders produce no file', 'ok' => true],
            ['key' => 'cron', 'label' => 'Cron (recommended)', 'value' => $cron['cron'], 'ok' => true],
            ['key' => 'memory', 'label' => 'Memory limit ≥ 256M', 'value' => $mem, 'ok' => $memBytes < 0 || $memBytes >= 256 * 1024 * 1024],
            ['key' => 'exec', 'label' => 'max_execution_time', 'value' => $exec === 0 ? 'unlimited' : $exec.'s', 'ok' => $exec === 0 || $exec >= 30],
        ];
    }

    public static function envWritable(): bool
    {
        $env = base_path('.env');

        return file_exists($env) ? is_writable($env) : is_writable(base_path());
    }

    /** @return array{ok: bool, cronOk: bool, queueOk: bool, cron: string, queue: string} */
    public static function cronStatus(): array
    {
        $beat = static::readBeat('cron-heartbeat');
        $queueBeat = static::readBeat('queue-heartbeat');
        $sync = config('queue.default') === 'sync';
        $cronOk = $beat !== null && $beat > time() - 180;
        $queueOk = $sync || ($queueBeat !== null && $queueBeat > time() - 600);

        return [
            'ok' => $cronOk && $queueOk,
            'cronOk' => $cronOk,
            'queueOk' => $queueOk,
            'cron' => $beat ? 'ran '.max(0, time() - $beat).'s ago' : 'waiting for first run…',
            'queue' => $sync ? 'sync (runs inline)' : ($queueBeat ? 'worker seen '.max(0, time() - $queueBeat).'s ago' : 'no worker detected'),
        ];
    }

    public static function beat(string $name): void
    {
        @file_put_contents(storage_path('app/'.$name), (string) time());
    }

    private static function readBeat(string $name): ?int
    {
        $f = storage_path('app/'.$name);

        return is_file($f) ? (int) file_get_contents($f) : null;
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }

    /** Set keys in .env (creating it from .env.example if needed). */
    public static function writeEnv(array $values): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            copy(base_path('.env.example'), $path);
        }
        $env = file_get_contents($path);
        foreach ($values as $key => $value) {
            $value = (string) $value;
            $quoted = $value === '' || preg_match('/[\s#"\'$=]/', $value) ? '"'.addcslashes($value, '"\\$').'"' : $value;
            $line = $key.'='.$quoted;
            if (preg_match('/^#?\s*'.preg_quote($key, '/').'=.*$/m', $env)) {
                $env = preg_replace('/^#?\s*'.preg_quote($key, '/').'=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env, 1);
            } else {
                $env = rtrim($env)."\n".$line."\n";
            }
        }
        file_put_contents($path, $env);
    }

    public static function freeSpace(): string
    {
        $b = @disk_free_space(storage_path());
        if (! $b) {
            return 'Unknown space';
        }

        return $b > 1024 ** 3 ? round($b / 1024 ** 3).' GB' : round($b / 1024 ** 2).' MB';
    }

    public static function dbReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
