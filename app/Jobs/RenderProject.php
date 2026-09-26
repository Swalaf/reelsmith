<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Setting;
use App\Services\SceneMedia;
use App\Support\Branding;
use App\Support\Ffmpeg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders a project to MP4.
 *
 *  1. Visuals — generates any missing scene image/clip with the connected Image/Video providers
 *  2. Voice   — speaks each scene's narration with the chosen voice (Voice providers)
 *  3. Scenes  — one segment per scene: Ken Burns on stills (or the clip), fades, captions timed
 *               to the narration, brand/free-plan watermark, voiceover
 *  4. Final   — concatenates the segments and mixes background music ducked under the voice
 *
 * Any step whose providers are missing or failing degrades gracefully (colour card, silence)
 * and is logged; only an FFmpeg failure fails the render, and then credits are refunded.
 */
class RenderProject implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public const TRACKS = [
        'Uplifting Corporate' => [261.63, 329.63, 392.00, 0.20],
        'Soft Focus' => [220.00, 277.18, 329.63, 0.12],
        'Night Drive' => [196.00, 233.08, 293.66, 0.16],
        'Morning Run' => [293.66, 369.99, 440.00, 0.30],
    ];

    public function __construct(public int $projectId) {}

    public function handle(SceneMedia $media): void
    {
        @set_time_limit(0);
        $project = Project::with('user')->find($this->projectId);
        if (! $project) {
            return;
        }
        $started = microtime(true);
        $work = storage_path('app/render-tmp/'.$project->id.'-'.Str::lower(Str::random(6)));
        File::ensureDirectoryExists($work);

        try {
            $scenes = array_values($project->scenes ?: [['id' => 1, 'caption' => $project->name, 'narration' => '', 'dur' => 5, 'c' => '#23343f']]);
            $used = [];
            $n = count($scenes);

            // 1. Visuals
            foreach ($scenes as $i => $s) {
                $this->progress($project, 5 + (int) (40 * $i / $n), 'Visuals · scene '.($i + 1).' of '.$n);
                $needs = in_array($s['src'] ?? 'AI Image', ['AI Image', 'AI Video'], true)
                    && (empty($s['img']) || (($s['src'] ?? '') === 'AI Video' && empty($s['clip'])));
                if ($needs) {
                    try {
                        $r = $media->visual($project, $s);
                        $scenes[$i] = $r['scene'];
                        $used[] = $r['provider'];
                    } catch (\Throwable $e) {
                        ActivityLog::record("Project #{$project->id} scene ".($i + 1).' visual: '.mb_substr($e->getMessage(), 0, 300).' — using a colour card', 'ai', 'WARNING');
                    }
                }
            }
            $project->update(['scenes' => $scenes]);

            // 2. Voice
            $voice = $project->voice ?: 'Theo';
            foreach ($scenes as $i => $s) {
                $this->progress($project, 45 + (int) (20 * $i / $n), 'Voice · scene '.($i + 1).' of '.$n);
                try {
                    $r = $media->voice($project, $s, $voice);
                    $scenes[$i] = $r['scene'];
                    if ($r['provider']) {
                        $used[] = $r['provider'];
                    }
                } catch (\Throwable $e) {
                    ActivityLog::record("Project #{$project->id} scene ".($i + 1).' voice: '.mb_substr($e->getMessage(), 0, 300).' — silent scene', 'ai', 'WARNING');
                }
            }
            $project->update(['scenes' => $scenes]);

            if (! Ffmpeg::binary()) {
                ActivityLog::record("FFmpeg not found; project #{$project->id} media generated but not assembled", 'ffmpeg', 'WARNING');
                $this->finish($project, null, $started, $used);

                return;
            }

            // 3. Scenes
            [$w, $h] = $this->size($project->ratio);
            $segments = [];
            foreach ($scenes as $i => $s) {
                $this->progress($project, 65 + (int) (25 * $i / $n), 'Rendering · scene '.($i + 1).' of '.$n);
                $segments[] = $this->segment($project, $s, $i, $w, $h, $work);
            }

            // 4. Concat + music
            $this->progress($project, 92, 'Mixing audio');
            $list = $work.'/list.txt';
            file_put_contents($list, implode("\n", array_map(fn ($f) => "file '".str_replace("'", "'\\''", $f)."'", $segments))."\n");
            $joined = $work.'/joined.mp4';
            Ffmpeg::run(['-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-movflags', '+faststart', $joined]);

            $disk = Storage::disk('public');
            $disk->makeDirectory('renders');
            $rel = 'renders/project-'.$project->id.'-'.time().'.mp4';
            $out = $disk->path($rel);

            $music = $this->music($project, $work);
            if ($music) {
                Ffmpeg::run(['-i', $joined, '-stream_loop', '-1', '-i', $music, '-filter_complex',
                    '[1:a]aformat=sample_rates=44100:channel_layouts=stereo,volume=0.35[m];'
                    .'[m][0:a]sidechaincompress=threshold=0.02:ratio=12:attack=15:release=450[duck];'
                    .'[0:a][duck]amix=inputs=2:duration=first:normalize=0,afade=t=out:st='.max(0, Ffmpeg::duration($joined) - 1.2).':d=1.2[a]',
                    '-map', '0:v', '-map', '[a]', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', $out]);
            } else {
                File::copy($joined, $out);
            }

            if ($project->output_path && $project->output_path !== $rel) {
                $disk->delete($project->output_path);
            }
            $this->finish($project, $rel, $started, $used);
        } catch (\Throwable $e) {
            $project->update(['status' => 'Failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'render_stage' => 'Failed']);
            if ($project->credits_used > 0) {
                $project->user?->adjustCredits($project->credits_used, 'Refund for failed render #'.$project->id);
            }
            ActivityLog::record("Render of project #{$project->id} failed: ".mb_substr($e->getMessage(), 0, 300).' — credits refunded', 'queue', 'ERROR');
        } finally {
            File::deleteDirectory($work);
        }
    }

    public function failed(\Throwable $e): void
    {
        Project::whereKey($this->projectId)->where('status', 'Processing')->update(['status' => 'Failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
    }

    /** @return array{0:int,1:int} */
    private function size(string $ratio): array
    {
        $values = (array) Setting::get('values', []);
        $short = match ($values['set_rendering_default_resolution'] ?? '1080p') {
            '720p' => 720, '4K' => 2160, default => 1080,
        };
        $long = (int) round($short * 16 / 9 / 2) * 2;

        return match ($ratio) {
            '16:9' => [$long, $short],
            '1:1' => [$short, $short],
            '4:5' => [$short, (int) round($short * 5 / 4 / 2) * 2],
            default => [$short, $long],
        };
    }

    private function segment(Project $project, array $s, int $i, int $w, int $h, string $work): string
    {
        $disk = Storage::disk('public');
        $audio = ! empty($s['audio']) && $disk->exists($s['audio']) ? $disk->path($s['audio']) : null;
        $voiceLen = $audio ? Ffmpeg::duration($audio) : 0;
        $dur = round(max((float) ($s['dur'] ?? 5), $voiceLen + 0.4, 1.5), 2);
        $frames = (int) ceil($dur * 30);

        $clip = ! empty($s['clip']) && $disk->exists($s['clip']) ? $disk->path($s['clip']) : null;
        $img = ! empty($s['img']) && $disk->exists($s['img']) ? $disk->path($s['img']) : null;
        if ($clip) {
            $inputs = ['-stream_loop', '-1', '-t', (string) $dur, '-i', $clip];
            $bg = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},fps=30,setsar=1";
        } elseif ($img) {
            $inputs = ['-loop', '1', '-framerate', '30', '-t', (string) $dur, '-i', $img];
            $zw = (int) round($w * 1.25 / 2) * 2;
            $zh = (int) round($h * 1.25 / 2) * 2;
            // Alternate zoom-in / zoom-out between scenes for a gentle Ken Burns effect.
            $z = $i % 2 === 0 ? "min(1+0.12*on/{$frames}\\,1.12)" : "max(1.12-0.12*on/{$frames}\\,1)";
            $bg = "[0:v]scale={$zw}:{$zh}:force_original_aspect_ratio=increase,crop={$zw}:{$zh},"
                ."zoompan=z='{$z}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s={$w}x{$h}:fps=30,setsar=1";
        } else {
            $color = ltrim((string) ($s['c'] ?? '#23343f'), '#');
            $color = preg_match('/^[0-9a-fA-F]{6}$/', $color) ? $color : '23343f';
            $inputs = ['-f', 'lavfi', '-t', (string) $dur, '-i', "color=c=0x{$color}:s={$w}x{$h}:r=30"];
            $bg = '[0:v]setsar=1';
        }
        $inputs = array_merge($inputs, $audio ? ['-i', $audio] : ['-f', 'lavfi', '-t', (string) $dur, '-i', 'anullsrc=r=44100:cl=stereo']);

        $v = $bg;
        if (($s['tr'] ?? 'Fade') !== 'Cut') {
            $v .= ',fade=t=in:st=0:d=0.35,fade=t=out:st='.max(0, $dur - 0.35).':d=0.35';
        }
        $v .= $this->captions($project, $s, $i, $w, $dur, $voiceLen, $work);
        $v .= $this->watermarks($project, $w, $work, $i);

        $out = $work.'/seg-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.mp4';
        Ffmpeg::run(array_merge($inputs, [
            '-filter_complex', $v.',format=yuv420p[v];[1:a]aresample=44100,aformat=channel_layouts=stereo,apad[a]',
            '-map', '[v]', '-map', '[a]', '-t', (string) $dur, '-r', '30',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-c:a', 'aac', '-b:a', '160k', '-ar', '44100', $out,
        ]));

        return $out;
    }

    /** Narration shown as 3–4 word caption chunks timed across the voiceover (karaoke-style). */
    private function captions(Project $project, array $s, int $i, int $w, float $dur, float $voiceLen, string $work): string
    {
        $cap = array_merge(['size' => 44, 'pos' => 'Bottom', 'color' => '#ffffff', 'bg' => 'None', 'font' => 'Archivo Black', 'anim' => 'Karaoke'], (array) $project->captions);
        if ($cap['anim'] === 'Off' || ! Ffmpeg::hasDrawtext() || ! ($font = $this->font((string) $cap['font']))) {
            return '';
        }
        $text = trim((string) (($s['narration'] ?? '') ?: ($s['caption'] ?? '')));
        if ($text === '') {
            return '';
        }
        $words = preg_split('/\s+/', $text);
        $chunks = array_chunk($words, count($words) > 12 ? 4 : 3);
        $span = $voiceLen > 0 ? $voiceLen : $dur;
        $upper = in_array($cap['font'], ['Archivo Black', 'Bebas Neue'], true);
        $size = (int) round(((int) $cap['size']) * $w / 1080 * 1.3);
        $y = match ($cap['pos']) {
            'Top' => 'h*0.12', 'Middle' => '(h-text_h)/2', default => 'h*0.78-text_h'
        };
        $color = '0x'.(preg_match('/^#?([0-9a-fA-F]{6})$/', (string) $cap['color'], $m) ? $m[1] : 'ffffff');
        $box = $cap['bg'] === 'Box' ? ':box=1:boxcolor=black@0.55:boxborderw='.(int) ($size * 0.35) : '';
        $shadow = $cap['bg'] !== 'Box' ? ':shadowcolor=black@0.6:shadowx=3:shadowy=3:borderw=2:bordercolor=black@0.35' : '';

        $f = '';
        foreach ($chunks as $k => $chunk) {
            $from = round($span * $k / count($chunks), 2);
            $to = round($span * ($k + 1) / count($chunks), 2);
            $file = $work."/cap-{$i}-{$k}.txt";
            $line = implode(' ', $chunk);
            file_put_contents($file, $upper ? mb_strtoupper($line) : $line);
            $f .= ",drawtext=fontfile='{$this->esc($font)}':textfile='{$this->esc($file)}':fontsize={$size}:fontcolor={$color}"
                ."{$box}{$shadow}:x=(w-text_w)/2:y={$y}:enable='between(t\\,{$from}\\,{$to})'";
        }

        return $f;
    }

    private function watermarks(Project $project, int $w, string $work, int $i): string
    {
        if (! Ffmpeg::hasDrawtext() || ! ($font = $this->font(''))) {
            return '';
        }
        $f = '';
        $brand = $project->user?->brandKit() ?? [];
        if (! empty($brand['wm']) && ! empty($brand['name'])) {
            file_put_contents($file = $work."/wm-{$i}.txt", $brand['name']);
            [$x, $y] = match ($brand['wmPos'] ?? 'Top right') {
                'Top left' => ['w*0.05', 'h*0.04'], 'Bottom left' => ['w*0.05', 'h*0.94-text_h'],
                'Bottom right' => ['w*0.95-text_w', 'h*0.94-text_h'], default => ['w*0.95-text_w', 'h*0.04'],
            };
            $f .= ",drawtext=fontfile='{$this->esc($font)}':textfile='{$this->esc($file)}':fontsize=".(int) ($w / 30).":fontcolor=white@0.8:x={$x}:y={$y}";
        }
        $toggles = (array) Setting::get('toggles', []);
        if (($toggles['watermarkFree'] ?? true) && (float) ($project->user?->plan?->price ?? 0) <= 0 && ! $project->user?->isAdmin()) {
            file_put_contents($file = $work."/wmf-{$i}.txt", 'Made with '.Branding::appName());
            $f .= ",drawtext=fontfile='{$this->esc($font)}':textfile='{$this->esc($file)}':fontsize=".(int) ($w / 36).':fontcolor=white@0.7:x=(w-text_w)/2:y=h*0.955-text_h';
        }

        return $f;
    }

    /** Background music: an uploaded file (storage/app/public/music/<slug>.mp3) or a generated ambient pad. */
    private function music(Project $project, string $work): ?string
    {
        $track = $project->music;
        if (! $track || ! isset(self::TRACKS[$track])) {
            return null;
        }
        foreach (['mp3', 'm4a', 'wav'] as $ext) {
            $file = Storage::disk('public')->path('music/'.Str::slug($track).'.'.$ext);
            if (is_file($file)) {
                return $file;
            }
        }
        [$a, $b, $c, $pulse] = self::TRACKS[$track];
        $expr = "0.22*sin(2*PI*{$a}*t)+0.16*sin(2*PI*{$b}*t)+0.13*sin(2*PI*{$c}*t)+0.08*sin(2*PI*".($a / 2).'*t)';
        $pad = $work.'/music.wav';
        Ffmpeg::run(['-f', 'lavfi', '-i', "aevalsrc=({$expr})*(0.75+0.25*sin(2*PI*{$pulse}*t)):s=44100:d=20",
            '-af', 'lowpass=f=1800,afade=t=in:d=2,aecho=0.8:0.7:120:0.25', '-ac', '2', $pad]);

        return $pad;
    }

    private function font(string $preferred): ?string
    {
        $dir = storage_path('app/fonts');
        $candidates = array_filter([
            $preferred ? $dir.'/'.$preferred.'.ttf' : null,
            Setting::get('caption_font'),
            $dir.'/default.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/Library/Fonts/Arial Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
        ]);
        foreach ($candidates as $f) {
            if (is_file($f)) {
                return $f;
            }
        }

        return null;
    }

    private function esc(string $path): string
    {
        return str_replace(['\\', ':', "'"], ['/', '\\:', "\\'"], $path);
    }

    private function progress(Project $project, int $pct, string $stage): void
    {
        $project->forceFill(['render_progress' => min(99, $pct), 'render_stage' => $stage])->saveQuietly();
    }

    private function finish(Project $project, ?string $rel, float $started, array $used): void
    {
        $providers = implode(' · ', array_unique(array_filter(array_merge(explode(' · ', (string) $project->providers_used), explode(' + ', implode(' + ', $used))))));
        $project->update(['status' => 'Completed', 'output_path' => $rel, 'error' => null, 'render_progress' => 100, 'render_stage' => 'Done',
            'render_seconds' => (int) (microtime(true) - $started), 'providers_used' => mb_substr($providers, 0, 250)]);
        ActivityLog::record(sprintf('Render job for project #%d completed in %.1fs', $project->id, microtime(true) - $started), 'queue');
    }
}
