<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $table = 'workflows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nodes' => 'array', 'edges' => 'array', 'settings' => 'array', 'live' => 'boolean', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
