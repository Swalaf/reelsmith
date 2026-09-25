<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Setting;
use App\Support\Ffmpeg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Renders a project to MP4 with FFmpeg: one solid-colour clip per scene with its caption,
 * concatenated in order. AI visuals/voice are layered on top of this pipeline by providers;
 * without FFmpeg on the server the job still completes but produces no file.
 */
class RenderProject implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $projectId) {}

    public function handle(): void
    {
        $project = Project::find($this->projectId);
        if (! $project) {
            return;
        }

        $started = microtime(true);
        $bin = Ffmpeg::binary();

        if (! $bin) {
            ActivityLog::record("FFmpeg not found; project #{$project->id} marked complete without a file", 'ffmpeg', 'WARNING');
            $this->finish($project, null, $started);

            return;
        }

        [$w, $h] = match ($project->ratio) {
            '16:9' => [1280, 720], '1:1' => [1080, 1080], '4:5' => [1080, 1350], default => [720, 1280],
        };

        $disk = Storage::disk('public');
        $disk->makeDirectory('renders');
        $rel = 'renders/project-'.$project->id.'-'.time().'.mp4';
        $out = $disk->path($rel);

        $scenes = $project->scenes ?: [['caption' => $project->name, 'dur' => 5, 'c' => '#23343f']];
        $font = $this->font();
        $fontOpt = $font ? 'fontfile='.str_replace(':', '\\:', $font).':' : '';
        $inputs = [];
        $filters = [];
        foreach (array_values($scenes) as $i => $s) {
            $color = ltrim((string) ($s['c'] ?? '#23343f'), '#');
            $inputs = array_merge($inputs, ['-f', 'lavfi', '-i', "color=c=0x{$color}:s={$w}x{$h}:d=".max(1, (int) ($s['dur'] ?? 5)).':r=30']);
            $text = str_replace(['\\', "'", ':', '%'], ['\\\\', "\u{2019}", '\\:', '\\%'], (string) ($s['caption'] ?? ''));
            $filters[] = "[{$i}:v]drawtext={$fontOpt}text='{$text}':fontcolor=white:fontsize=".(int) ($w / 14).":x=(w-text_w)/2:y=h*0.72:box=1:boxcolor=black@0.35:boxborderw=18[v{$i}]";
        }
        $n = count($scenes);
        $concat = implode('', array_map(fn ($i) => "[v{$i}]", range(0, $n - 1)))."concat=n={$n}:v=1:a=0[out]";

        $ok = $this->run(array_merge([$bin, '-y'], $inputs, ['-filter_complex', implode(';', $filters).';'.$concat, '-map', '[out]', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $out]));
        if (! $ok) {
            // drawtext needs an FFmpeg built with libfreetype and a font; retry without captions rather than failing.
            ActivityLog::record("Project #{$project->id}: FFmpeg could not burn in captions (drawtext/libfreetype missing?) — rendering without them", 'ffmpeg', 'WARNING');
            $plain = implode('', array_map(fn ($i) => "[{$i}:v]", range(0, $n - 1)))."concat=n={$n}:v=1:a=0[out]";
            $ok = $this->run(array_merge([$bin, '-y'], $inputs, ['-filter_complex', $plain, '-map', '[out]', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $out]));
        }

        if (! $ok) {
            $project->update(['status' => 'Failed', 'error' => 'FFmpeg render failed']);
            $project->user?->adjustCredits($project->credits_used, 'Refund for failed render #'.$project->id);
            ActivityLog::record("Render of project #{$project->id} failed; credits refunded", 'queue', 'ERROR');

            return;
        }

        $this->finish($project, $rel, $started);
    }

    /** A bold TTF for captions: the `caption_font` setting, else a common system font. */
    private function font(): ?string
    {
        $candidates = array_filter([
            Setting::get('caption_font'),
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

    private function run(array $cmd): bool
    {
        $p = new Process($cmd);
        $p->setTimeout(540);
        $p->run();

        return $p->isSuccessful();
    }

    private function finish(Project $project, ?string $rel, float $started): void
    {
        $project->update(['status' => 'Completed', 'output_path' => $rel, 'error' => null]);
        ActivityLog::record(sprintf('Render job for project #%d completed in %.1fs', $project->id, microtime(true) - $started), 'queue');
    }
}
