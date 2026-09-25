<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Signs out suspended accounts and sends them to the "account suspended" screen. */
class EnsureActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->status === 'Suspended' && ! session()->has('impersonator_id')) {
            Auth::logout();
            $request->session()->invalidate();

            return $request->expectsJson() ? response()->json(['message' => 'Account suspended.', 'suspended' => true], 403) : redirect('/suspended');
        }

        return $next($request);
    }
}
