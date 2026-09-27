<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Where generated media is served from.
 *
 * Everything is written to the local public disk first (FFmpeg needs real files). When cloud
 * storage is configured (Admin → Settings → Storage, or MEDIA_DISK=s3 + AWS_* in .env), finished
 * files are copied to the bucket and their URLs point there; until a copy exists, the local URL
 * is used, so nothing breaks while an upload is pending or if the bucket is unreachable.
 */
class Media
{
    /** Folders worth offloading (deliverables and user media); scratch folders stay local. */
    public const SYNC_DIRS = ['renders', 'runs', 'media', 'projects', 'characters', 'productions'];

    private static array $known = [];

    private static ?Filesystem $cloud = null;

    private static ?string $fakeUrl = null;

    /** Tests: use any disk as the "bucket", served from $url. */
    public static function fake(Filesystem $disk, string $url = 'https://cdn.test'): void
    {
        static::$cloud = $disk;
        static::$fakeUrl = $url;
        static::$known = [];
    }

    /** Cloud settings: Admin → Settings → Storage overrides .env. */
    public static function config(): ?array
    {
        if (static::$fakeUrl !== null) {
            return ['url' => static::$fakeUrl];
        }
        $driver = Setting::value('set_storage_driver');
        $fromSettings = $driver && $driver !== 'Local disk' && Setting::value('set_storage_bucket');
        if (! $fromSettings && env('MEDIA_DISK', env('FILESYSTEM_DISK')) !== 's3') {
            return null;
        }
        $c = $fromSettings ? [
            'key' => Setting::value('set_storage_access_key'), 'secret' => Setting::value('set_storage_secret_key'),
            'region' => Setting::value('set_storage_region') ?: 'auto', 'bucket' => Setting::value('set_storage_bucket'),
            'endpoint' => Setting::value('set_storage_endpoint') ?: null, 'url' => Setting::value('set_storage_public_url') ?: null,
        ] : [
            'key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'), 'endpoint' => env('AWS_ENDPOINT') ?: null, 'url' => env('AWS_URL') ?: null,
        ];
        if (! $c['bucket'] || ! $c['key'] || ! $c['secret']) {
            return null;
        }

        return $c + ['driver' => 's3', 'use_path_style_endpoint' => (bool) $c['endpoint'], 'visibility' => 'public', 'throw' => true];
    }

    public static function enabled(): bool
    {
        return static::config() !== null;
    }

    public static function cloud(): ?Filesystem
    {
        if (static::$cloud === null && ($c = static::config())) {
            static::$cloud = Storage::build($c);
        }

        return static::$cloud;
    }

    /** Forget the cached disk (after settings change). */
    public static function reset(): void
    {
        static::$cloud = null;
        static::$fakeUrl = null;
        static::$known = [];
    }

    public static function url(string $rel): string
    {
        if (static::inCloud($rel) && ($c = static::config())) {
            return $c['url'] ? rtrim($c['url'], '/').'/'.ltrim($rel, '/') : static::cloud()->url($rel);
        }

        return Storage::disk('public')->url($rel);
    }

    public static function inCloud(string $rel): bool
    {
        if (! array_key_exists($rel, static::$known)) {
            static::$known[$rel] = static::enabled() && DB::table('cloud_files')->where('path', $rel)->exists();
        }

        return static::$known[$rel];
    }

    /** Copy one local file to the bucket. Returns false (and logs) on failure; the local URL keeps working. */
    public static function push(string $rel): bool
    {
        $local = Storage::disk('public');
        if (! ($cloud = static::cloud()) || ! $local->exists($rel)) {
            return false;
        }
        try {
            $stream = fopen($local->path($rel), 'r');
            $cloud->writeStream($rel, $stream, ['visibility' => 'public', 'ContentType' => mime_content_type($local->path($rel)) ?: 'application/octet-stream']);
            is_resource($stream) && fclose($stream);
            DB::table('cloud_files')->updateOrInsert(['path' => $rel], ['size' => $local->size($rel), 'pushed_at' => now()]);
            static::$known[$rel] = true;

            return true;
        } catch (\Throwable $e) {
            ActivityLog::record('Cloud upload failed for '.$rel.': '.mb_substr($e->getMessage(), 0, 200), 'storage', 'ERROR');

            return false;
        }
    }

    /** Upload now (when cloud storage is on) and return the URL — for URLs that get stored, like run outputs. */
    public static function publish(string $rel): string
    {
        if (static::enabled() && ! static::inCloud($rel)) {
            static::push($rel);
        }

        return static::url($rel);
    }

    public static function delete(string $rel): void
    {
        Storage::disk('public')->delete($rel);
        if (static::enabled() && static::inCloud($rel)) {
            try {
                static::cloud()->delete($rel);
            } catch (\Throwable) {
            }
        }
        DB::table('cloud_files')->where('path', $rel)->delete();
        static::$known[$rel] = false;
    }

    /** Make sure a file exists locally (downloading it from the bucket if the local copy was pruned). */
    public static function local(string $rel): ?string
    {
        $local = Storage::disk('public');
        if (! $local->exists($rel) && static::inCloud($rel)) {
            try {
                $local->writeStream($rel, static::cloud()->readStream($rel));
            } catch (\Throwable $e) {
                ActivityLog::record('Cloud download failed for '.$rel.': '.mb_substr($e->getMessage(), 0, 200), 'storage', 'ERROR');
            }
        }

        return $local->exists($rel) ? $local->path($rel) : null;
    }

    /** Round-trip test used by Admin → Settings → Storage → Connection. @return array{0:string,1:bool} */
    public static function health(): array
    {
        if (! static::enabled()) {
            return ['Local disk · '.(is_writable(storage_path('app')) ? 'writable' : 'not writable'), is_writable(storage_path('app'))];
        }
        $t = microtime(true);
        try {
            $probe = '.reelsmith-health-'.bin2hex(random_bytes(3));
            static::cloud()->put($probe, 'ok');
            static::cloud()->delete($probe);

            return ['Healthy · '.round((microtime(true) - $t) * 1000).' ms', true];
        } catch (\Throwable $e) {
            return ['Error · '.mb_substr($e->getMessage(), 0, 80), false];
        }
    }
}
