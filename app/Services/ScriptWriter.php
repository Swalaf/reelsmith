<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * Turns an idea into a script and a timed scene plan.
 *
 * Uses the highest-priority connected OpenAI-compatible text provider when one is
 * configured (OpenRouter, Groq, a self-hosted vLLM/Ollama, …). When none is available,
 * or the provider fails, it falls back to a deterministic local writer so the product
 * keeps working without any AI keys.
 */
class ScriptWriter
{
    /** @return array{script: array, scenes: array, provider: string} */
    public function write(array $idea): array
    {
        $topic = trim($idea['topic'] ?? '') ?: 'Untitled video';
        $seconds = (int) filter_var($idea['dur'] ?? '30', FILTER_SANITIZE_NUMBER_INT) ?: 30;

        foreach ($this->textProviders() as $provider) {
            try {
                $out = $this->viaProvider($provider, $idea, $topic, $seconds);
                if ($out) {
                    $provider->increment('requests');

                    return $out + ['provider' => $provider->name];
                }
            } catch (\Throwable $e) {
                ActivityLog::record($provider->name.' script generation failed: '.$e->getMessage().' → fallback', 'ai', 'WARNING');
            }
        }

        return $this->local($topic, $idea, $seconds) + ['provider' => 'Built-in writer'];
    }

    private function textProviders()
    {
        return AiProvider::where('category', 'Text')->where('status', 'connected')
            ->where('driver', 'openai')->orderBy('priority')->get()
            ->filter(fn ($p) => $p->api_key && $p->base_url);
    }

    private function viaProvider(AiProvider $p, array $idea, string $topic, int $seconds): ?array
    {
        $prompt = "Write a short-form video script for {$idea['platform']} ({$idea['ratio']}, about {$seconds} seconds, tone: {$idea['tone']}).\n"
            ."Topic: {$topic}\n\n"
            .'Reply with JSON only: {"title":string,"hook":string,"body":string,"cta":string,'
            .'"scenes":[{"prompt":visual description,"narration":spoken line,"caption":max 4 words,"dur":seconds}]}. '
            .'Use 4 to 6 scenes whose durations add up to about '.$seconds.' seconds.';

        $res = Http::timeout(45)->withToken((string) $p->api_key)->acceptJson()
            ->post(rtrim($p->base_url, '/').'/chat/completions', [
                'model' => $p->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a concise video scriptwriter. Output valid JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
            ]);

        if (! $res->successful()) {
            throw new \RuntimeException('HTTP '.$res->status());
        }

        $text = (string) $res->json('choices.0.message.content');
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
