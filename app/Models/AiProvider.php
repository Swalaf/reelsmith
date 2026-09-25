<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

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
        if (! $this->needsKey()) {
            return ['ok' => true, 'ms' => 0, 'message' => 'No key required'];
        }
        if (! $this->api_key) {
            return ['ok' => false, 'ms' => 0, 'message' => 'No API key configured'];
        }

        $base = rtrim((string) $this->base_url, '/');
        $key = (string) $this->api_key;
        $start = microtime(true);

        try {
            $http = Http::timeout(15)->acceptJson();
            $res = match ($this->driver) {
                'gemini' => $http->get($base.'/v1beta/models', ['key' => $key]),
                'elevenlabs' => $http->withHeaders(['xi-api-key' => $key])->get($base.'/user'),
                'huggingface' => $http->withToken($key)->get('https://huggingface.co/api/whoami-v2'),
                'cloudflare' => $http->withToken($key)->get($base.'/user/tokens/verify'),
                'openai' => $http->withToken($key)->get($base.'/models'),
                default => $http->withToken($key)->get($base),
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => (int) ((microtime(true) - $start) * 1000), 'message' => 'Could not reach '.$base];
        }

        $ms = (int) ((microtime(true) - $start) * 1000);
        $status = $res->status();
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'ms' => $ms, 'message' => $status.' · invalid API key'];
        }
        if ($status === 429) {
            return ['ok' => false, 'ms' => $ms, 'message' => '429 · rate limited'];
        }
        if ($status >= 500 || ($this->driver !== 'generic' && ! $res->successful())) {
            return ['ok' => false, 'ms' => $ms, 'message' => 'HTTP '.$status];
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
