<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $key = $token ? ApiKey::findActive($token) : null;
        if (! $key || ! $key->user || $key->user->status === 'Suspended') {
            return response()->json(['error' => 'invalid_api_key', 'message' => 'Provide a valid key: Authorization: Bearer rsk_live_…'], 401);
        }

        $bucket = 'api-key:'.$key->id;
        if (RateLimiter::tooManyAttempts($bucket, $key->rate_limit)) {
            return response()->json(['error' => 'rate_limited', 'retry_after' => RateLimiter::availableIn($bucket)], 429);
        }
        RateLimiter::hit($bucket, 60);

        $key->forceFill(['last_used_at' => now(), 'requests' => $key->requests + 1])->save();
        Auth::setUser($key->user);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
