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
        $p->setTimeout(10);
        $p->run();

        return preg_match('/ffmpeg version (\S+)/', $p->getOutput(), $m) ? $m[1] : null;
    }
}
