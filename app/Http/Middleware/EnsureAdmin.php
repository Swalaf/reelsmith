<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AccountController;
use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isAdmin()) {
            return $request->expectsJson() ? response()->json(['message' => 'Administrators only.'], 403) : redirect('/studio');
        }

        // Admin → Settings → Security → "Require 2FA for admins"
        if (AccountController::adminsNeed2fa() && ! $request->user()->hasTwoFactor()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Turn on two-factor authentication (Studio → Settings) to use the admin area.'], 403)
                : redirect('/studio/settings?setup2fa=1');
        }

        return $next($request);
    }
}
