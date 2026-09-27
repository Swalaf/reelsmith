<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\WorkflowRun;
use App\Support\Ffmpeg;
use Illuminate\Support\Facades\Storage;

/**
 * AI Platform → Repurpose: turns one finished video into platform-ready outputs.
 *
 * Video outputs are cut with FFmpeg (16:9 master, vertical clips at scene boundaries),
 * text outputs are written by the connected Text provider (or a template without one),
 * captions come from the scene timings, and thumbnails are frame grabs.
 */
class Repurposer
{
    public const OUTPUTS = [
        'yt' => 'YouTube video', 'shorts' => 'Shorts', 'reels' => 'Instagram Reels', 'tiktok' => 'TikTok clips',
        'li' => 'LinkedIn post', 'x' => 'X thread', 'blog' => 'Blog article', 'cap' => 'Captions', 'thumb' => 'Thumbnail concepts',
    ];

    public function __construct(private AiGateway $ai = new AiGateway) {}

    public static function analysis(Project $p): array
    {
        $text = trim(implode(' ', array_column($p->scenes ?? [], 'narration')));
        $words = str_word_count($text);
        preg_match_all('/\b[a-zA-Z]{6,}\b/', mb_strtolower($text), $m);
        $top = collect($m[0])->reject(fn ($w) => in_array($w, ['because', 'should', 'without', 'really', 'things', 'follow', 'someone'], true))
            ->countBy()->sortDesc()->keys()->take(5)->map(fn ($w) => ucfirst($w))->values()->all();

        return [
            'rows' => [['Transcribed', $p->durationLabel().' · '.number_format($words).' words'], ['Speakers identified', $p->voice ? '1 · '.$p->voice : '1'],
                ['Key moments', (string) count($p->scenes ?? [])], ['Topics', (string) count($top)], ['Quotable lines', (string) max(1, preg_match_all('/[.!?]/', $text))]],
            'topics' => $top ?: [$p->name],
        ];
    }

    public function execute(WorkflowRun $run): void
    {
        @set_time_limit(0);
        $run->update(['status' => 'Running', 'started_at' => now()]);
        $project = Project::where('user_id', $run->user_id)->find($run->input['project_id'] ?? 0);
        $disk = Storage::disk('public');
        $dir = 'runs/'.$run->id;
        $disk->makeDirectory($dir);
        $steps = (array) $run->steps;
        $output = [];
        $failed = null;

        foreach ($steps as $i => $step) {
            $steps[$i]['status'] = 'run';
            $run->update(['steps' => $steps]);
            $t = microtime(true);
            try {
                if (! $project || ! $project->output_path || ! $disk->exists($project->output_path)) {
                    throw new \RuntimeException('the source video file is missing — render it first');
                }
                [$meta, $files] = $this->output($step['key'], $project, $disk->path($project->output_path), $dir);
                $output[$step['key']] = $files;
                $steps[$i] = array_merge($steps[$i], ['status' => 'done', 'meta' => $meta, 'dur' => round(microtime(true) - $t, 1).'s', 'files' => $files]);
            } catch (\Throwable $e) {
                $failed ??= $step['title'].': '.mb_substr($e->getMessage(), 0, 200);
                $steps[$i] = array_merge($steps[$i], ['status' => 'fail', 'meta' => mb_substr($e->getMessage(), 0, 200), 'dur' => round(microtime(true) - $t, 1).'s']);
            }
            $run->update(['steps' => $steps, 'output' => $output]);
        }

        $run->update(['status' => $failed ? 'Failed' : 'Completed', 'error' => $failed, 'finished_at' => now()]);
        ActivityLog::record("Repurpose run #{$run->id} ".($failed ? 'finished with errors: '.$failed : 'completed'), 'queue', $failed ? 'WARNING' : 'INFO');
        Webhooks::dispatch($run->user, $failed ? 'run.failed' : 'run.completed', ['run_id' => 'run_'.$run->id, 'name' => $run->name, 'output' => $output]);
    }

    /** @return array{0:string,1:array} meta, public URLs */
    private function output(string $key, Project $p, string $src, string $dir): array
    {
        $disk = Storage::disk('public');
        $url = fn (string $rel) => $disk->url($rel);

        switch ($key) {
            case 'yt':
                $rel = $dir.'/youtube-16x9.mp4';
                $this->reframe($src, $disk->path($rel), 1920, 1080);

                return ['1920×1080 · blurred fill', [$url($rel)]];

            case 'shorts':
            case 'reels':
            case 'tiktok':
                $files = [];
                $clips = $this->moments($p, $src, $key === 'shorts' ? 10 : ($key === 'reels' ? 6 : 8));
                foreach ($clips as $k => [$from, $len]) {
                    $rel = $dir."/{$key}-".($k + 1).'.mp4';
                    $this->reframe($src, $disk->path($rel), 1080, 1920, $from, $len);
                    $files[] = $url($rel);
                }

                return [count($files).' clip'.(count($files) === 1 ? '' : 's').' · 9:16', $files];

            case 'li':
            case 'x':
            case 'blog':
                $brief = ['li' => 'a LinkedIn post (120-200 words, professional, 3 short paragraphs, end with a question)', 'x' => 'an X thread of 5-9 numbered posts, each under 270 characters', 'blog' => 'a blog article in Markdown of about 800 words with H2 sections'][$key];
                $script = trim(($p->script['hook'] ?? '').' '.($p->script['body'] ?? '').' '.($p->script['cta'] ?? '')) ?: implode(' ', array_column($p->scenes ?? [], 'narration'));
                try {
                    $text = $this->ai->text('You repurpose video scripts into written content. Reply with the content only.', "Turn this video script into {$brief}.\nTitle: {$p->name}\nScript: {$script}")['result'];
                    $via = 'AI';
                } catch (\Throwable) {
                    $text = "# {$p->name}\n\n".$script;
                    $via = 'template';
                }
                $rel = $dir.'/'.['li' => 'linkedin-post.txt', 'x' => 'x-thread.txt', 'blog' => 'blog-article.md'][$key];
                $disk->put($rel, $text);

                return [str_word_count($text).' words · '.$via, [$url($rel)]];

            case 'cap':
                [$srt, $vtt] = $this->captions($p, $src);
                $disk->put($dir.'/captions.srt', $srt);
                $disk->put($dir.'/captions.vtt', $vtt);

                return ['SRT + VTT', [$url($dir.'/captions.srt'), $url($dir.'/captions.vtt')]];

            case 'thumb':
                $len = max(1, Ffmpeg::duration($src));
                $files = [];
                foreach ([0.12, 0.37, 0.62, 0.87] as $k => $f) {
                    $rel = $dir.'/thumbnail-'.($k + 1).'.jpg';
                    Ffmpeg::run(['-ss', (string) round($len * $f, 2), '-i', $src, '-frames:v', '1', '-q:v', '3', $disk->path($rel)]);
                    $files[] = $url($rel);
                }

                return ['4 frame grabs', $files];
        }
        throw new \RuntimeException('unknown output');
    }

    /** Scene boundaries (start, length) — each scene is a natural short clip. */
    private function moments(Project $p, string $src, int $max): array
    {
        $total = Ffmpeg::duration($src);
        $out = [];
        $t = 0.0;
        foreach ($p->scenes ?? [] as $s) {
            $len = (float) ($s['dur'] ?? 5);
            if ($t >= $total) {
                break;
            }
            $out[] = [$t, min($len, $total - $t)];
            $t += $len;
        }
        if (! $out) {
            $out = [[0, min(58, $total)]];
        }

        return array_slice($out, 0, $max);
    }

    /** Fit the source into WxH over a blurred, zoomed copy of itself (no black bars). */
    private function reframe(string $src, string $dest, int $w, int $h, float $from = 0, ?float $len = null): void
    {
        $args = $from > 0 ? ['-ss', (string) round($from, 2)] : [];
        $args = array_merge($args, ['-i', $src]);
        if ($len) {
            $args = array_merge($args, ['-t', (string) round($len, 2)]);
        }
        Ffmpeg::run(array_merge($args, ['-filter_complex',
            "[0:v]split[a][b];[a]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},boxblur=20:2[bg];[b]scale={$w}:{$h}:force_original_aspect_ratio=decrease[fg];[bg][fg]overlay=(W-w)/2:(H-h)/2,format=yuv420p[v]",
            '-map', '[v]', '-map', '0:a?', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', $dest]));
    }

    private function captions(Project $p, string $src): array
    {
        $srt = [];
        $vtt = ['WEBVTT', ''];
        $t = 0.0;
        $n = 1;
        foreach ($p->scenes ?? [] as $s) {
            $dur = (float) ($s['dur'] ?? 5);
            if (! empty($s['audio']) && Storage::disk('public')->exists($s['audio'])) {
                $dur = max($dur, Ffmpeg::duration(Storage::disk('public')->path($s['audio'])) + 0.4);
            }
            $text = trim((string) (($s['narration'] ?? '') ?: ($s['caption'] ?? '')));
            if ($text !== '') {
                $srt[] = $n++."\n".$this->ts($t, ',').' --> '.$this->ts($t + $dur, ',')."\n".$text."\n";
                $vtt[] = $this->ts($t, '.').' --> '.$this->ts($t + $dur, '.')."\n".$text."\n";
            }
            $t += $dur;
        }

        return [implode("\n", $srt), implode("\n", $vtt)];
    }

    private function ts(float $s, string $sep): string
    {
        $ms = (int) round(($s - floor($s)) * 1000);

        return sprintf('%02d:%02d:%02d%s%03d', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60), (int) $s % 60, $sep, $ms);
    }
}
