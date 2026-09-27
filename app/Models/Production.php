<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Production extends Model
{
    protected $table = 'productions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'toggles' => 'array', 'scenes' => 'array', 'shots' => 'array', 'screenplay' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
