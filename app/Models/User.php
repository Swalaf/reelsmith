<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'status', 'credits', 'plan_id', 'brand', 'onboarding', 'email_verified_at', 'prefs'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const DEFAULT_BRAND = [
        'name' => 'My Brand', 'web' => 'example.com', 'cta' => 'Learn more at example.com',
        'c0' => '#0e3b43', 'c1' => '#2fb3a5', 'c2' => '#f4efe6',
        'heading' => 'Archivo Black', 'body' => 'DM Sans', 'wm' => true, 'wmPos' => 'Top right',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'brand' => 'array',
            'onboarding' => 'array',
            'credits' => 'integer',
            'prefs' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret;
    }

    /** Notification preferences, with defaults. */
    public function pref(string $key): bool
    {
        $prefs = (array) $this->prefs + ['renderDone' => true, 'lowCredits' => true, 'product' => false, 'weekly' => false];

        return (bool) ($prefs[$key] ?? false);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function brandKit(): array
    {
        return array_merge(self::DEFAULT_BRAND, $this->brand ?? []);
    }

    public function adjustCredits(int $amount, string $reason): void
    {
        $this->credits = max(0, $this->credits + $amount);
        $this->save();
        CreditTransaction::create(['user_id' => $this->id, 'amount' => $amount, 'reason' => $reason]);
    }
}
