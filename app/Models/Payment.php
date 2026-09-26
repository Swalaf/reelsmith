<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['user_id', 'plan_id', 'reference', 'gateway_ref', 'item', 'gateway', 'amount', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
