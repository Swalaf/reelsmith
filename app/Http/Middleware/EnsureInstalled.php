<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next)
    {
        $installing = $request->is('install') || $request->is('install/*');

        if (! Installer::installed() && ! $installing && ! $request->is('up')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Application is not installed yet.'], 503)
                : redirect('/install');
        }
        if (Installer::installed() && $installing) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Already installed.'], 403)
                : redirect('/');
        }

        return $next($request);
    }
}
