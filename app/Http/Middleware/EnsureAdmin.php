<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isAdmin()) {
            return $request->expectsJson() ? response()->json(['message' => 'Administrators only.'], 403) : redirect('/studio');
        }

        return $next($request);
    }
}
