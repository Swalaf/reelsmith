<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Calls the AI providers configured in Admin → AI Providers.
 *
 * Every capability tries the connected providers of its category in priority order and
 * falls back to the next one on any failure (the "Automatic fallback" behaviour described
 * in the design). Each method returns the name of the provider that succeeded, or throws
 * when none could do the job.
 */
class AiGateway
{
    /** Studio voice names → provider-specific voices. */
    public const VOICES = [
        'Marcus' => ['edge' => 'en-US-GuyNeural', 'openai' => 'onyx', 'elevenlabs' => 'pNInz6obpgDQGcFmaJgB'],
        'Ava' => ['edge' => 'en-US-AriaNeural', 'openai' => 'nova', 'elevenlabs' => 'EXAVITQu4vr4xnSDxMaL'],
        'Theo' => ['edge' => 'en-GB-RyanNeural', 'openai' => 'fable', 'elevenlabs' => 'ErXwobaYiN019PkySvjV'],
        'Priya' => ['edge' => 'en-IN-NeerjaNeural', 'openai' => 'shimmer', 'elevenlabs' => '21m00Tcm4TlvDq8Ny2uM'],
        'Lena' => ['edge' => 'de-DE-KatjaNeural', 'openai' => 'alloy', 'elevenlabs' => 'MF3mGyEYCl7XYWbV9V6O'],
        'Diego' => ['edge' => 'es-MX-JorgeNeural', 'openai' => 'echo', 'elevenlabs' => 'TxGEqnHWrfWFTfGW9XjX'],
        'Sofia' => ['edge' => 'en-AU-NatashaNeural', 'openai' => 'nova', 'elevenlabs' => 'AZnzlk1XvdvUeBnXmlld'],
        'Noah' => ['edge' => 'en-US-ChristopherNeural', 'openai' => 'onyx', 'elevenlabs' => 'yoZ06aMxZJJ28mfd3POQ'],
        'Camille' => ['edge' => 'fr-FR-DeniseNeural', 'openai' => 'shimmer', 'elevenlabs' => 'EXAVITQu4vr4xnSDxMaL'],
    ];

    private const IMAGE_SIZE = ['9:16' => [768, 1344], '16:9' => [1344, 768], '1:1' => [1024, 1024], '4:5' => [896, 1120]];

    // ---------------------------------------------------------------- text

    /**
     * Chat completion.
     *
     * @return array{provider: string, result: string}
     */
    public function text(string $system, string $prompt): array
    {
        return $this->attempt('Text', fn (AiProvider $p) => match ($p->driver) {
            'openai', 'huggingface' => $this->openAiChat($p, $system, $prompt),
            'gemini' => $this->geminiChat($p, $system, $prompt),
            'cloudflare' => $this->cloudflareChat($p, $system, $prompt),
            default => throw new \RuntimeException('text is not supported by driver '.$p->driver),
        });
    }

    private function openAiChat(AiProvider $p, string $system, string $prompt): string
    {
        $base = $p->driver === 'huggingface' ? 'https://router.huggingface.co/v1' : rtrim((string) $p->base_url, '/');
        $res = $this->http($p)->withToken((string) $p->api_key)->post($base.'/chat/completions', [
            'model' => $p->model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.7,
        ]);

        return (string) $this->ok($res)->json('choices.0.message.content');
    }

    private function geminiChat(AiProvider $p, string $system, string $prompt): string
    {
        $res = $this->http($p)->post(rtrim((string) $p->base_url, '/').'/v1beta/models/'.$p->model.':generateContent?key='.urlencode((string) $p->api_key), [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        ]);

        return (string) $this->ok($res)->json('candidates.0.content.parts.0.text');
    }

    private function cloudflareChat(AiProvider $p, string $system, string $prompt): string
    {
        $res = $this->http($p)->withToken((string) $p->api_key)->post($this->cfRun($p, $p->model), [
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 1500,
        ]);

        return (string) $this->ok($res)->json('result.response');
    }

    // --------------------------------------------------------------- image

    /** Generate a still for a scene and write it to $dest (PNG/JPEG bytes). */
    public function image(string $prompt, string $ratio, string $dest): string
    {
        [$w, $h] = self::IMAGE_SIZE[$ratio] ?? self::IMAGE_SIZE['9:16'];

        return $this->attempt('Image', function (AiProvider $p) use ($prompt, $ratio, $w, $h, $dest) {
            $bytes = match ($p->driver) {
                'cloudflare' => $this->cloudflareImage($p, $prompt, $w, $h),
                'huggingface' => $this->body($this->http($p)->withToken((string) $p->api_key)->withHeaders(['Accept' => 'image/png'])
                    ->post('https://router.huggingface.co/hf-inference/models/'.$this->hfImageModel($p->model), ['inputs' => $prompt, 'parameters' => ['width' => $w, 'height' => $h]])),
                'openai' => $this->openAiImage($p, $prompt, $w, $h),
                'stability' => $this->body($this->http($p)->withToken((string) $p->api_key)->withHeaders(['Accept' => 'image/*'])->asMultipart()
                    ->post('https://api.stability.ai/v2beta/stable-image/generate/core', [
                        ['name' => 'prompt', 'contents' => $prompt], ['name' => 'aspect_ratio', 'contents' => $ratio], ['name' => 'output_format', 'contents' => 'png'],
                    ])),
                'replicate' => $this->download($this->replicate($p, $this->replicateModel($p->model, 'image'), ['prompt' => $prompt, 'aspect_ratio' => $ratio, 'output_format' => 'png'])),
                'fal' => $this->download((string) $this->ok($this->fal($p, $p->model ?: 'fal-ai/flux/schnell', ['prompt' => $prompt, 'image_size' => ['width' => $w, 'height' => $h]]))->json('images.0.url')),
                default => throw new \RuntimeException('images are not supported by driver '.$p->driver),
            };
            $this->assertMedia($bytes, 'image');
            file_put_contents($dest, $bytes);
        })['provider'];
    }

    private function cloudflareImage(AiProvider $p, string $prompt, int $w, int $h): string
    {
        $res = $this->http($p)->withToken((string) $p->api_key)->post($this->cfRun($p, $this->cfImageModel($p->model)), ['prompt' => $prompt, 'width' => $w, 'height' => $h, 'steps' => 4]);
        $this->ok($res);
        // FLUX returns JSON with base64; SDXL models return raw PNG bytes.
        if (str_contains((string) $res->header('Content-Type'), 'json')) {
            return base64_decode((string) $res->json('result.image'));
        }

        return $res->body();
    }

    private function openAiImage(AiProvider $p, string $prompt, int $w, int $h): string
    {
        $size = $w > $h ? '1792x1024' : ($w < $h ? '1024x1792' : '1024x1024');
        $res = $this->ok($this->http($p)->withToken((string) $p->api_key)->post(rtrim((string) $p->base_url, '/').'/images/generations', [
            'model' => $p->model ?: 'dall-e-3', 'prompt' => $prompt, 'size' => $size, 'n' => 1, 'response_format' => 'b64_json',
        ]));

        return $res->json('data.0.b64_json') ? base64_decode($res->json('data.0.b64_json')) : $this->download((string) $res->json('data.0.url'));
    }

    // --------------------------------------------------------------- video

    /** Generate a short motion clip for a scene and write it to $dest (MP4). */
    public function video(string $prompt, string $ratio, int $seconds, ?string $imagePath, string $dest): string
    {
        return $this->attempt('Video', function (AiProvider $p) use ($prompt, $ratio, $seconds, $imagePath, $dest) {
            $dataUri = $imagePath && is_file($imagePath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($imagePath)) : null;
            $url = match ($p->driver) {
                'fal' => (string) $this->ok($this->fal($p, $this->falVideoModel($p->model), array_filter(['prompt' => $prompt, 'aspect_ratio' => $ratio])))->json('video.url'),
                'replicate' => $this->replicate($p, $this->replicateModel($p->model, 'video'), ['prompt' => $prompt, 'aspect_ratio' => $ratio]),
                'luma' => $this->luma($p, $prompt, $ratio),
                'runway' => $dataUri ? $this->runway($p, $prompt, $ratio, $seconds, $dataUri) : throw new \RuntimeException('Runway needs a scene image first'),
                default => throw new \RuntimeException('video is not supported by driver '.$p->driver),
            };
            $bytes = $this->download($url);
            $this->assertMedia($bytes, 'video');
            file_put_contents($dest, $bytes);
        }, 600)['provider'];
    }

    private function luma(AiProvider $p, string $prompt, string $ratio): string
    {
        $base = rtrim((string) $p->base_url ?: 'https://api.lumalabs.ai/dream-machine/v1', '/');
        $id = $this->ok($this->http($p)->withToken((string) $p->api_key)->post($base.'/generations', [
            'prompt' => $prompt, 'model' => $p->model ?: 'ray-2', 'aspect_ratio' => $ratio, 'duration' => '5s', 'resolution' => '720p',
        ]))->json('id');

        return $this->poll(fn () => $this->ok($this->http($p)->withToken((string) $p->api_key)->get($base.'/generations/'.$id)),
            fn (Response $r) => $r->json('state') === 'completed' ? (string) $r->json('assets.video') : ($r->json('state') === 'failed' ? throw new \RuntimeException((string) $r->json('failure_reason')) : null));
    }

    private function runway(AiProvider $p, string $prompt, string $ratio, int $seconds, string $imageDataUri): string
    {
        $base = rtrim((string) $p->base_url ?: 'https://api.dev.runwayml.com/v1', '/');
        $http = fn () => $this->http($p)->withToken((string) $p->api_key)->withHeaders(['X-Runway-Version' => '2024-11-06']);
        $id = $this->ok($http()->post($base.'/image_to_video', [
            'model' => str_starts_with((string) $p->model, 'gen') && $p->model !== 'gen-3-alpha' ? $p->model : 'gen3a_turbo',
            'promptImage' => $imageDataUri, 'promptText' => mb_substr($prompt, 0, 900),
            'ratio' => in_array($ratio, ['16:9', '1:1'], true) ? '1280:768' : '768:1280', 'duration' => $seconds > 5 ? 10 : 5,
        ]))->json('id');

        return $this->poll(fn () => $this->ok($http()->get($base.'/tasks/'.$id)),
            fn (Response $r) => $r->json('status') === 'SUCCEEDED' ? (string) $r->json('output.0') : ($r->json('status') === 'FAILED' ? throw new \RuntimeException((string) $r->json('failure')) : null));
    }

    // --------------------------------------------------------------- voice

    /** Speak $text with the chosen Studio voice and write audio (MP3/WAV) to $dest. */
    public function speech(string $text, string $voice, string $dest): string
    {
        $map = self::VOICES[$voice] ?? self::VOICES['Theo'];

        return $this->attempt('Voice', function (AiProvider $p) use ($text, $map, $dest) {
            match ($p->driver) {
                'openai' => file_put_contents($dest, $this->assertMedia($this->body($this->http($p)->withToken((string) $p->api_key)
                    ->post(rtrim((string) $p->base_url, '/').'/audio/speech', ['model' => $p->model ?: 'tts-1', 'voice' => $map['openai'], 'input' => $text, 'response_format' => 'mp3'])), 'audio')),
                'elevenlabs' => file_put_contents($dest, $this->assertMedia($this->body($this->http($p)->withHeaders(['xi-api-key' => (string) $p->api_key, 'Accept' => 'audio/mpeg'])
                    ->post(rtrim((string) $p->base_url, '/').'/text-to-speech/'.$map['elevenlabs'].'?output_format=mp3_44100_128', ['text' => $text, 'model_id' => $p->model ?: 'eleven_multilingual_v2'])), 'audio')),
                'none' => $this->edgeTts($text, $map['edge'], $dest),
                'local' => $this->piper($p, $text, $dest),
                default => throw new \RuntimeException('speech is not supported by driver '.$p->driver),
            };
            if (! is_file($dest) || filesize($dest) < 100) {
                throw new \RuntimeException('no audio produced');
            }
        })['provider'];
    }

    public static function edgeTtsBinary(): ?string
    {
        return Setting::get('edge_tts_path') ?: (new ExecutableFinder)->find('edge-tts', null, [base_path('vendor/bin'), '/usr/local/bin', getenv('HOME').'/.local/bin']);
    }

    private function edgeTts(string $text, string $voice, string $dest): void
    {
        $bin = static::edgeTtsBinary() ?? throw new \RuntimeException('edge-tts is not installed (pip install edge-tts)');
        $proc = new Process([$bin, '--voice', $voice, '--text', $text, '--write-media', $dest]);
        $proc->setTimeout(120);
        $proc->run();
        if (! $proc->isSuccessful()) {
            throw new \RuntimeException('edge-tts failed: '.trim(mb_substr($proc->getErrorOutput(), 0, 200)));
        }
    }

    private function piper(AiProvider $p, string $text, string $dest): void
    {
        $bin = (new ExecutableFinder)->find('piper') ?? throw new \RuntimeException('piper is not installed');
        $model = (string) $p->model;
        if (! is_file($model)) {
            throw new \RuntimeException('set the Piper model to the full path of a .onnx voice file');
        }
        $proc = new Process([$bin, '--model', $model, '--output_file', $dest]);
        $proc->setInput($text);
        $proc->setTimeout(120);
        $proc->run();
        if (! $proc->isSuccessful()) {
            throw new \RuntimeException('piper failed');
        }
    }

    // ------------------------------------------------------------- plumbing

    /** Slug of a provider to try first (the one picked in the Studio), then the usual order. */
    public ?string $prefer = null;

    /** Connected providers for a category, best first. */
    public function providers(string $category): Collection
    {
        return AiProvider::where('category', $category)->where('status', 'connected')->orderBy('priority')->get()
            ->filter(fn (AiProvider $p) => ! $p->needsKey() || $p->api_key)
            ->sortBy(fn (AiProvider $p) => $p->slug === $this->prefer ? 0 : 1, SORT_REGULAR)->values();
    }

    public function available(string $category): bool
    {
        return $this->providers($category)->isNotEmpty();
    }

    private function attempt(string $category, callable $fn, int $timeout = 120): array
    {
        $errors = [];
        foreach ($this->providers($category) as $p) {
            try {
                $this->timeout = $timeout;
                $result = $fn($p);
                $p->increment('requests');

                return ['provider' => $p->name, 'result' => $result];
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 240);
                $errors[] = $p->name.': '.$msg;
                ActivityLog::record("{$p->name} ({$category}) failed: {$msg} → trying next provider", 'ai', 'WARNING');
                if (preg_match('/\b(401|403)\b/', $msg)) {
                    $p->update(['status' => 'error', 'last_test' => $msg]);
                } elseif (str_contains($msg, '429')) {
                    $p->update(['status' => 'rate', 'last_test' => $msg]);
                }
            }
        }

        throw new \RuntimeException($errors ? 'All '.$category.' providers failed — '.implode(' | ', $errors) : 'No '.$category.' AI provider is connected');
    }

    private int $timeout = 120;

    private function http(AiProvider $p): PendingRequest
    {
        return Http::timeout($this->timeout)->connectTimeout(15);
    }

    private function ok(Response $r): Response
    {
        if (! $r->successful()) {
            $detail = $r->json('error.message') ?? $r->json('error') ?? $r->json('detail') ?? $r->json('message') ?? mb_substr($r->body(), 0, 160);
            throw new \RuntimeException('HTTP '.$r->status().' '.(is_string($detail) ? $detail : json_encode($detail)));
        }

        return $r;
    }

    private function body(Response $r): string
    {
        return $this->ok($r)->body();
    }

    private function download(string $url): string
    {
        if ($url === '') {
            throw new \RuntimeException('provider returned no file URL');
        }

        return $this->ok(Http::timeout(300)->get($url))->body();
    }

    private function assertMedia(string $bytes, string $kind): string
    {
        if (strlen($bytes) < 100 || str_starts_with(ltrim($bytes), '{') || str_starts_with(ltrim($bytes), '<')) {
            throw new \RuntimeException("provider returned no {$kind} data");
        }

        return $bytes;
    }

    /** Poll an async job every 5s (up to ~8 minutes) until $done returns a value. */
    private function poll(callable $fetch, callable $done): string
    {
        for ($i = 0; $i < 96; $i++) {
            $value = $done($fetch());
            if ($value !== null && $value !== '') {
                return $value;
            }
            sleep($i === 0 ? 2 : 5);
        }
        throw new \RuntimeException('timed out waiting for the provider');
    }

    private function fal(AiProvider $p, string $model, array $input): Response
    {
        return $this->http($p)->withHeaders(['Authorization' => 'Key '.$p->api_key])->post('https://fal.run/'.ltrim($model, '/'), $input);
    }

    private function replicate(AiProvider $p, string $model, array $input): string
    {
        $http = fn () => $this->http($p)->withToken((string) $p->api_key);
        $res = $this->ok($http()->withHeaders(['Prefer' => 'wait=60'])->post('https://api.replicate.com/v1/models/'.$model.'/predictions', ['input' => $input]));
        $get = (string) $res->json('urls.get');
        $pick = function (Response $r) {
            if ($r->json('status') === 'failed' || $r->json('status') === 'canceled') {
                throw new \RuntimeException('prediction '.$r->json('status').': '.$r->json('error'));
            }
            $out = $r->json('output');

            return $r->json('status') === 'succeeded' ? (string) (is_array($out) ? ($out[0] ?? '') : $out) : null;
        };

        return $pick($res) ?? $this->poll(fn () => $this->ok($http()->get($get)), $pick);
    }

    private function cfRun(AiProvider $p, string $model): string
    {
        $base = rtrim((string) $p->base_url, '/');
        if (! str_contains($base, '/accounts/') || str_contains($base, 'YOUR_ACCOUNT_ID')) {
            throw new \RuntimeException('set the Base URL to https://api.cloudflare.com/client/v4/accounts/<your account id>');
        }

        return $base.'/ai/run/'.$model;
    }

    private function cfImageModel(?string $m): string
    {
        return match ($m) {
            null, '', 'flux-1-schnell' => '@cf/black-forest-labs/flux-1-schnell',
            'stable-diffusion-xl-lightning' => '@cf/bytedance/stable-diffusion-xl-lightning',
            default => $m,
        };
    }

    private function hfImageModel(?string $m): string
    {
        return match ($m) {
            null, '', 'stable-diffusion-xl' => 'stabilityai/stable-diffusion-xl-base-1.0',
            'flux-1-schnell' => 'black-forest-labs/FLUX.1-schnell',
            default => $m,
        };
    }

    private function replicateModel(?string $m, string $kind): string
    {
        return match ($m) {
            null, '' => $kind === 'image' ? 'black-forest-labs/flux-schnell' : 'lightricks/ltx-video',
            'flux-1.1-pro' => 'black-forest-labs/flux-1.1-pro',
            'hunyuan-video' => 'tencent/hunyuan-video',
            default => $m,
        };
    }

    private function falVideoModel(?string $m): string
    {
        return match ($m) {
            null, '', 'ltx-video' => 'fal-ai/ltx-video',
            'wan-2.1-t2v' => 'fal-ai/wan-t2v',
            default => $m,
        };
    }
}
