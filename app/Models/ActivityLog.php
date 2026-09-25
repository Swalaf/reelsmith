<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = ['level', 'channel', 'message'];

    public static function record(string $message, string $channel = 'app', string $level = 'INFO'): void
    {
        try {
            static::create(compact('message', 'channel', 'level'));
        } catch (\Throwable) {
            // Logging must never break a request (e.g. before migrations have run).
        }
    }
}
