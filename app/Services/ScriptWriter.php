<?php

namespace App\Services;

use App\Models\ActivityLog;

/**
 * Turns an idea into a script and a timed scene plan.
 *
 * Uses the connected Text providers (see AiGateway, with fallback between them). When
 * none is connected, or all fail, a deterministic local writer keeps the product working
 * without any AI keys.
 */
class ScriptWriter
{
    public function __construct(private AiGateway $ai = new AiGateway) {}

    /** @return array{script: array, scenes: array, provider: string} */
    public function write(array $idea): array
    {
        $topic = trim($idea['topic'] ?? '') ?: 'Untitled video';
        $seconds = (int) filter_var($idea['dur'] ?? '30', FILTER_SANITIZE_NUMBER_INT) ?: 30;

        if ($this->ai->available('Text')) {
            try {
                return $this->viaProvider($idea, $topic, $seconds);
            } catch (\Throwable $e) {
                ActivityLog::record('Script generation failed: '.mb_substr($e->getMessage(), 0, 300).' → built-in writer', 'ai', 'WARNING');
            }
        }

        return $this->local($topic, $idea, $seconds) + ['provider' => 'Built-in writer'];
    }

    private function viaProvider(array $idea, string $topic, int $seconds): array
    {
        $extra = trim(implode("\n", array_filter([
            ! empty($idea['description']) ? 'Details: '.$idea['description'] : null,
            ! empty($idea['audience']) ? 'Audience: '.$idea['audience'] : null,
            ! empty($idea['cta']) ? 'Call to action: '.$idea['cta'] : null,
            ! empty($idea['language']) ? 'Language: '.$idea['language'] : null,
        ])));
        $prompt = 'Write a short-form video script for '.($idea['platform'] ?? 'TikTok').' ('.($idea['ratio'] ?? '9:16').", about {$seconds} seconds, tone: ".($idea['tone'] ?? 'Friendly').").\n"
            ."Topic: {$topic}\n{$extra}\n\n"
            .'Reply with JSON only: {"title":string,"hook":string,"body":string,"cta":string,'
            .'"scenes":[{"prompt":detailed visual description for an image generator (no text in image),"narration":spoken line,"caption":max 4 words,"dur":seconds}]}. '
            .'Use 4 to 6 scenes whose durations add up to about '.$seconds.' seconds. The narration of all scenes read in order must equal hook + body + cta.';

        $out = $this->ai->text('You are a concise video scriptwriter. Output valid JSON only.', $prompt);
        $text = $out['result'];
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        $data = json_decode($text, true);
        if (! is_array($data) || empty($data['scenes'])) {
            throw new \RuntimeException('Unparseable response');
        }

        $scenes = [];
        foreach (array_slice($data['scenes'], 0, 8) as $s) {
            $scenes[] = $this->scene((string) ($s['prompt'] ?? ''), (string) ($s['narration'] ?? ''), (string) ($s['caption'] ?? ''), (int) ($s['dur'] ?? 5));
        }

        return [
            'script' => [
                'title' => (string) ($data['title'] ?? $topic),
                'hook' => (string) ($data['hook'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
                'cta' => (string) ($data['cta'] ?? ''),
            ],
            'scenes' => $this->numbered($scenes),
            'provider' => $out['provider'],
        ];
    }

    private function local(string $topic, array $idea, int $seconds): array
    {
        $hook = match ($idea['tone'] ?? 'Friendly') {
            'Bold' => "Stop scrolling. {$topic} is about to change how you think.",
            'Playful' => "Okay, real talk: {$topic}. Let's go!",
            'Calm' => "Take a breath. Let's talk about {$topic}.",
            'Inspirational' => "Every big change starts small. Here's {$topic}.",
            'Professional' => "Here's what you need to know about {$topic}.",
            default => "Ever wondered about {$topic}? Here's the quick version.",
        };
        $points = [
            "First, the one thing most people miss about {$topic}.",
            'Second, a simple habit that makes it effortless.',
            'Third, the result you can expect in days, not months.',
        ];
        $cta = 'Follow for more, and share this with someone who needs it.';

        $n = count($points) + 2;
        $base = max(2, intdiv($seconds, $n));
        $scenes = [$this->scene("Eye-catching opening shot that introduces {$topic}", $hook, 'Did you know?', $base)];
        foreach ($points as $i => $p) {
            $scenes[] = $this->scene('Clean illustrative shot for point '.($i + 1)." about {$topic}", $p, 'Tip '.($i + 1), $base);
        }
        $scenes[] = $this->scene('Brand end card with logo and call to action', $cta, 'Follow for more', max(2, $seconds - $base * ($n - 1)));

        return [
            'script' => ['title' => $topic, 'hook' => $hook, 'body' => implode(' ', $points), 'cta' => $cta],
            'scenes' => $this->numbered($scenes),
        ];
    }

    private function scene(string $prompt, string $narration, string $caption, int $dur): array
    {
        return ['prompt' => $prompt, 'narration' => $narration, 'caption' => $caption, 'dur' => max(2, min(20, $dur)),
            'v' => 'none', 'vp' => 0, 'src' => 'AI Image', 'tr' => 'Fade', 'rg' => false];
    }

    private function numbered(array $scenes): array
    {
        $colors = ['#2f4b4b', '#3d3447', '#30384a', '#5a4a30', '#23343f', '#4a3b31', '#33413a', '#27403f'];
        foreach ($scenes as $i => &$s) {
            $s['id'] = $i + 1;
            $s['c'] = $colors[$i % count($colors)];
        }

        return $scenes;
    }
}
