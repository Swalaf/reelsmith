<?php

namespace App\Support;

use App\Models\User;

class Boot
{
    public static function me(User $u): array
    {
        $plan = $u->plan;
        $cap = max($plan?->credits ?? 0, $u->credits, 1);

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'initials' => collect(preg_split('/\s+/', trim($u->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''),
            'credits' => number_format($u->credits),
            'creditsRaw' => $u->credits,
            'creditsLabel' => number_format($u->credits).' / '.number_format($cap),
            'creditsPct' => round(min(100, $u->credits / $cap * 100), 1).'%',
            'plan' => $plan?->name ?? 'Free',
            'planLabel' => ($plan?->name ?? 'Free').' plan',
            'impersonating' => session()->has('impersonator_id'),
        ];
    }
}
