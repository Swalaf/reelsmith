<?php

namespace App\Services;

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;

/**
 * Outgoing webhooks (API & Webhooks screen). Each delivery is a JSON POST signed with
 * `X-Reelsmith-Signature: sha256=<hmac of the body with the endpoint secret>`.
 */
class Webhooks
{
    public const EVENTS = ['video.completed', 'video.failed', 'run.completed', 'run.failed', 'image.completed'];

    public static function dispatch(?User $user, string $event, array $data): void
    {
        if (! $user) {
            return;
        }
        foreach (Webhook::where('user_id', $user->id)->where('active', true)->get() as $hook) {
            $events = (array) $hook->events;
            if ($events && ! in_array($event, $events, true) && ! in_array('*', $events, true)) {
                continue;
            }
            static::deliver($hook, $event, $data);
        }
    }

    public static function deliver(Webhook $hook, string $event, array $data): int
    {
        $body = json_encode(['event' => $event, 'created' => now()->toIso8601String(), 'data' => $data], JSON_UNESCAPED_SLASHES);
        $code = 0;
        try {
            $res = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json',
                'X-Reelsmith-Event' => $event,
                'X-Reelsmith-Signature' => 'sha256='.hash_hmac('sha256', $body, $hook->secret),
            ])->withBody($body, 'application/json')->post($hook->url);
            $code = $res->status();
        } catch (\Throwable) {
            $code = 0;
        }
        $hook->increment('deliveries');
        if ($code >= 200 && $code < 300) {
            $hook->increment('successes');
        }
        WebhookEvent::create(['user_id' => $hook->user_id, 'webhook_id' => $hook->id, 'direction' => 'OUT', 'event' => $event, 'code' => $code, 'target' => parse_url($hook->url, PHP_URL_HOST) ?: $hook->url]);

        return $code;
    }
}
