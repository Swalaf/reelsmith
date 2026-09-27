<?php

namespace App\Support;

use App\Models\Setting;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Ffmpeg
{
    public static function binary(): ?string
    {
        $configured = Setting::get('ffmpeg_path') ?: env('FFMPEG_PATH');
        if ($configured && is_executable($configured)) {
            return $configured;
        }

        return (new ExecutableFinder)->find('ffmpeg');
    }

    public static function version(): ?string
    {
        $bin = static::binary();
        if (! $bin) {
            return null;
        }
        $p = new Process([$bin, '-version']);
        $p->setTimeout(20);
        try {
            $p->run();
        } catch (\Throwable) {
            return null;
        }

        return preg_match('/ffmpeg version (\S+)/', $p->getOutput(), $m) ? $m[1] : null;
    }

    /** Does this build have the drawtext filter (needs libfreetype) for burned-in captions? */
    public static function hasDrawtext(): bool
    {
        static $cache = [];
        $bin = static::binary();
        if (! $bin) {
            return false;
        }
        if (! isset($cache[$bin])) {
            $p = new Process([$bin, '-hide_banner', '-filters']);
            $p->setTimeout(10);
            $p->run();
            $cache[$bin] = str_contains($p->getOutput(), 'drawtext');
        }

        return $cache[$bin];
    }

    /** Media duration in seconds (parsed from ffmpeg's banner; works without ffprobe). */
    public static function duration(string $file): float
    {
        $bin = static::binary();
        if (! $bin || ! is_file($file)) {
            return 0.0;
        }
        $p = new Process([$bin, '-hide_banner', '-i', $file]);
        $p->setTimeout(20);
        $p->run();

        return preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $p->getErrorOutput(), $m) ? $m[1] * 3600 + $m[2] * 60 + (float) $m[3] : 0.0;
    }

    /** Run ffmpeg with the given arguments; throws with the tail of stderr on failure. */
    public static function run(array $args, int $timeout = 600): void
    {
        $p = new Process(array_merge([static::binary(), '-hide_banner', '-loglevel', 'error', '-y'], $args));
        $p->setTimeout($timeout);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('FFmpeg: '.mb_substr(trim($p->getErrorOutput()), -400));
        }
    }
}
