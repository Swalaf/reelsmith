<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = ['user_id', 'name', 'prefix', 'last4', 'key_hash', 'rate_limit', 'requests', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Create a key and return [model, plaintext]. The plaintext is only ever shown once. */
    public static function issue(User $user, string $name, int $rate = 60): array
    {
        $plain = 'rsk_live_'.Str::random(32);
        $key = static::create([
            'user_id' => $user->id, 'name' => $name, 'prefix' => substr($plain, 0, 9),
            'last4' => substr($plain, -4), 'key_hash' => hash('sha256', $plain), 'rate_limit' => $rate,
        ]);

        return [$key, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        return static::where('key_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public function toClient(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'key' => $this->prefix.'••••'.$this->last4,
            'owner' => $this->user?->name ?? '—', 'req' => number_format($this->requests),
            'rate' => $this->rate_limit.' / min', 'last' => $this->last_used_at?->diffForHumans() ?? 'Never',
            'revoked' => $this->revoked_at !== null,
        ];
    }
}
