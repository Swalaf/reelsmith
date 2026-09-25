<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Project extends Model
{
    public const COLORS = ['#23343f', '#4a3b31', '#33413a', '#3d3447', '#5a4a30', '#27403f', '#4a2f33', '#30384a', '#41392c', '#2d3b2d'];

    protected $fillable = [
        'user_id', 'template_id', 'name', 'status', 'platform', 'ratio', 'duration', 'idea', 'script', 'scenes',
        'captions', 'voice', 'providers_used', 'credits_used', 'render_started_at', 'render_seconds', 'output_path', 'error',
    ];

    protected function casts(): array
    {
        return [
            'idea' => 'array', 'script' => 'array', 'scenes' => 'array', 'captions' => 'array',
            'render_started_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function totalSeconds(): int
    {
        $scenes = $this->scenes ?? [];

        return $scenes ? (int) array_sum(array_column($scenes, 'dur')) : (int) $this->duration;
    }

    public function durationLabel(): string
    {
        $t = $this->totalSeconds();

        return intdiv($t, 60).':'.str_pad((string) ($t % 60), 2, '0', STR_PAD_LEFT);
    }

    /** Credits a render of this project costs: script + one per scene visual + voice per started minute. */
    public function renderCost(): int
    {
        return 2 + count($this->scenes ?? []) + 6 * max(1, (int) ceil($this->totalSeconds() / 60));
    }

    public function outputUrl(): ?string
    {
        return $this->output_path ? Storage::disk('public')->url($this->output_path) : null;
    }

    public function toClient(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'dur' => $this->durationLabel(),
            'status' => $this->status,
            'date' => $this->created_at?->format('M d, Y'),
            'platform' => $this->platform,
            'ratio' => $this->ratio,
            'c' => self::COLORS[$this->id % 10],
            'ts' => $this->created_at?->timestamp ?? 0,
            'owner' => $this->user?->name,
            'credits' => $this->credits_used,
            'provs' => $this->providers_used ?: '—',
            'error' => $this->error,
            'url' => $this->outputUrl(),
            'idea' => $this->idea,
            'script' => $this->script,
            'scenes' => $this->scenes,
            'captions' => $this->captions,
            'voice' => $this->voice,
        ];
    }
}
