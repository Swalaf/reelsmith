<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $all = Cache::rememberForever('rs.settings', fn () => Schema::hasTable('settings')
                ? static::query()->pluck('value', 'key')->all() : []);
        } catch (\Throwable) {
            return $default;
        }

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('rs.settings');
    }
}
