<?php

namespace App\Models;

use App\Services\AiGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\ExecutableFinder;

class AiProvider extends Model
{
    protected $fillable = ['slug', 'name', 'mono', 'category', 'tier', 'status', 'model', 'models', 'api_key', 'base_url', 'driver', 'priority', 'requests', 'cost', 'last_test'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'models' => 'array', 'cost' => 'float'];
    }

    public function needsKey(): bool
    {
        return ! in_array($this->driver, ['none', 'local'], true);
    }

    public function maskedKey(): string
    {
        if (! $this->needsKey()) {
            return $this->driver === 'local' ? 'local' : 'not required';
        }
        $k = (string) $this->api_key;

        return $k === '' ? '' : substr($k, 0, min(6, max(0, strlen($k) - 4))).'••••••••'.substr($k, -4);
    }

    /**
     * Make a real, cheap authenticated request to the provider and report the result.
     *
     * @return array{ok: bool, ms: int, message: string}
     */
    public function testConnection(): array
    {
        if ($this->driver === 'none') {
            $bin = AiGateway::edgeTtsBinary();

            return $bin ? ['ok' => true, 'ms' => 0, 'message' => 'OK · edge-tts found'] : ['ok' => false, 'ms' => 0, 'message' => 'edge-tts not installed on the server (pip install edge-tts)'];
        }
        if ($this->driver === 'local') {
            $bin = (new ExecutableFinder)->find('piper');

            return $bin && is_file((string) $this->model) ? ['ok' => true, 'ms' => 0, 'message' => 'OK · piper + voice model found']
                : ['ok' => false, 'ms' => 0, 'message' => $bin ? 'Set the model to the path of a .onnx voice' : 'piper not installed on the server'];
        }
        if (! $this->api_key) {
            return ['ok' => false, 'ms' => 0, 'message' => 'No API key configured'];
        }

        $base = rtrim((string) $this->base_url, '/');
        $key = (string) $this->api_key;
        $start = microtime(true);
        // Providers without a cheap "who am I" endpoint are probed with an empty request:
        // a validation error (400/422) proves the key was accepted.
        $validationMeansOk = in_array($this->driver, ['fal', 'generic'], true);

        try {
            $http = Http::timeout(15)->acceptJson();
            $res = match ($this->driver) {
                'gemini' => $http->get($base.'/v1beta/models', ['key' => $key]),
                'elevenlabs' => $http->withHeaders(['xi-api-key' => $key])->get($base.'/user'),
                'huggingface' => $http->withToken($key)->get('https://huggingface.co/api/whoami-v2'),
                'cloudflare' => str_contains($base, '/accounts/') && ! str_contains($base, 'YOUR_ACCOUNT_ID')
                    ? $http->withToken($key)->get($base.'/ai/models/search', ['per_page' => 1])
                    : $http->withToken($key)->get('https://api.cloudflare.com/client/v4/user/tokens/verify'),
                'openai' => $http->withToken($key)->get($base.'/models'),
                'stability' => $http->withToken($key)->get('https://api.stability.ai/v1/user/account'),
                'replicate' => $http->withToken($key)->get('https://api.replicate.com/v1/account'),
                'fal' => $http->withHeaders(['Authorization' => 'Key '.$key])->post('https://fal.run/fal-ai/flux/schnell', (object) []),
                'luma' => $http->withToken($key)->get(($base ?: 'https://api.lumalabs.ai/dream-machine/v1').'/generations', ['limit' => 1]),
                'runway' => $http->withToken($key)->withHeaders(['X-Runway-Version' => '2024-11-06'])->get(($base ?: 'https://api.dev.runwayml.com/v1').'/organization'),
                default => $http->withToken($key)->get($base),
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => (int) ((microtime(true) - $start) * 1000), 'message' => 'Could not reach '.($base ?: $this->name)];
        }

        $ms = (int) ((microtime(true) - $start) * 1000);
        $status = $res->status();
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'ms' => $ms, 'message' => $status.' · invalid API key'];
        }
        if ($status === 429) {
            return ['ok' => false, 'ms' => $ms, 'message' => '429 · rate limited'];
        }
        if ($validationMeansOk && in_array($status, [400, 404, 422], true)) {
            return ['ok' => true, 'ms' => $ms, 'message' => 'OK · key accepted · '.$ms.' ms'];
        }
        if (! $res->successful()) {
            return ['ok' => false, 'ms' => $ms, 'message' => 'HTTP '.$status];
        }
        if ($this->driver === 'cloudflare' && (! str_contains($base, '/accounts/') || str_contains($base, 'YOUR_ACCOUNT_ID'))) {
            return ['ok' => false, 'ms' => $ms, 'message' => 'Token valid — now set Base URL to …/client/v4/accounts/<account id>'];
        }

        $count = is_array($res->json('data')) ? count($res->json('data')) : (is_array($res->json('models')) ? count($res->json('models')) : null);

        return ['ok' => true, 'ms' => $ms, 'message' => 'OK · '.$ms.' ms'.($count ? ' · '.$count.' models' : '')];
    }

    public function toClient(): array
    {
        return [
            'id' => $this->slug,
            'dbId' => $this->id,
            'name' => $this->name,
            'mono' => $this->mono,
            'cat' => $this->category,
            'tier' => $this->tier,
            'status' => $this->status,
            'model' => $this->model,
            'models' => $this->models ?? [],
            'key' => $this->maskedKey(),
            'hasKey' => (bool) $this->api_key || ! $this->needsKey(),
            'needsKey' => $this->needsKey(),
            'url' => $this->base_url ?: '—',
            'driver' => $this->driver,
            'priority' => $this->priority,
            'req' => number_format($this->requests),
            'cost' => '$'.number_format($this->cost, 2),
            'test' => $this->last_test ?: '—',
        ];
    }
}
