<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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

    public const MASK = '••••••••';

    /** Admin Settings form value (from the `values` setting), decrypting stored secrets. */
    public static function value(string $key, mixed $default = null): mixed
    {
        $v = ((array) static::get('values', []))[$key] ?? null;
        if (is_string($v) && str_starts_with($v, 'enc:')) {
            try {
                return Crypt::decryptString(substr($v, 4));
            } catch (\Throwable) {
                return $default;
            }
        }

        return ($v === null || $v === '') ? $default : $v;
    }

    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match('/(secret|password|api_key)/', $key);
    }

    /** Store Settings form values, encrypting secrets and keeping existing ones when the mask comes back. */
    public static function putValues(array $incoming): void
    {
        $current = (array) static::get('values', []);
        foreach ($incoming as $k => $v) {
            if (static::isSecretKey($k)) {
                if ($v === static::MASK) {
                    continue;
                }
                $v = $v === '' || $v === null ? '' : 'enc:'.Crypt::encryptString((string) $v);
            }
            $current[$k] = $v;
        }
        static::put('values', $current);
    }

    /** Values safe to send to the browser (secrets masked). */
    public static function publicValues(): array
    {
        $out = [];
        foreach ((array) static::get('values', []) as $k => $v) {
            $out[$k] = static::isSecretKey($k) ? ($v ? static::MASK : '') : $v;
        }

        return $out;
    }
}
