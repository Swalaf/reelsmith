<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\Plan;
use App\Models\Template;
use App\Services\Payments;
use App\Support\Branding;
use App\Support\DesignPage;
use App\Support\Ffmpeg;
use App\Support\Installer;
use Illuminate\Http\Request;

class PageController extends Controller
{
    private const SITE_PAGES = ['home', 'features', 'pricing', 'providers', 'templates', 'template', 'docs', 'contact', 'about', 'legal', 'changelog', 'checkout', 'login', 'register', 'forgot', 'reset', 'verify', 'twofa', 'onboarding', 'e404', 'e500', 'maintenance', 'suspended'];

    public function site(Request $request, string $page = 'home', ?string $arg = null)
    {
        $user = $request->user();
        if (in_array($page, ['login', 'register'], true) && $user) {
            return redirect($user->isAdmin() ? '/admin' : '/studio');
        }
        if (in_array($page, ['onboarding', 'verify'], true) && ! $user) {
            return redirect('/register');
        }
        if (! in_array($page, self::SITE_PAGES, true)) {
            $page = 'e404';
        }

        $boot = [
            'startPage' => $page,
            'plans' => Plan::withCount('users')->orderBy('sort')->get()->map->toClient()->values(),
            'templates' => Template::where('status', 'Published')->orderBy('id')->get()->map->toClient()->values(),
            'checkoutPlan' => $request->query('plan'),
            'checkoutResult' => $request->boolean('paid') ? 'paid' : ($request->boolean('failed') ? 'failed' : ($request->boolean('cancelled') ? 'cancelled' : null)),
            'payments' => Payments::status(),
        ];
        if ($page === 'legal') {
            $boot['legal'] = in_array($arg, ['terms', 'privacy', 'refund', 'cookies', 'license'], true) ? $arg : 'terms';
        }
        if ($page === 'reset') {
            $boot['resetToken'] = $arg;
            $boot['resetEmail'] = $request->query('email');
        }
        if ($page === 'template') {
            $boot['tpl'] = (int) $request->query('t', 0);
        }
        if ($page === 'onboarding') {
            $boot['providerStack'] = collect(['Text' => 'type', 'Image' => 'image', 'Voice' => 'mic', 'Video' => 'clapperboard'])->map(function ($icon, $cat) {
                $p = AiProvider::where('category', $cat)->where('status', 'connected')->orderBy('priority')->first();

                return ['cat' => $cat, 'name' => $p ? $p->name.($p->model ? ' · '.$p->model : '') : 'Not connected', 'icon' => 'icon-'.$icon,
                    'st' => $p ? 'Ready' : ($cat === 'Text' ? 'Built-in writer' : 'Unavailable'), 'c' => $p || $cat === 'Text' ? 'oklch(0.45 0.12 150)' : '#8a8c91'];
            })->values();
        }

        $titles = ['home' => 'AI video from idea to MP4', 'e404' => 'Page not found', 'e500' => 'Server error'];
        $status = ['e404' => 404, 'e500' => 500, 'maintenance' => 503][$page] ?? 200;

        return DesignPage::make('website', Branding::appName().' — '.($titles[$page] ?? ucfirst($page)), $boot, $status);
    }

    public function install()
    {
        return DesignPage::make('installer', 'Install '.config('app.name'), [
            'install' => [
                'requirements' => Installer::requirements(),
                'ffmpeg' => ['version' => Ffmpeg::version(), 'path' => Ffmpeg::binary()],
                'cron' => Installer::cronStatus(),
                'basePath' => base_path(),
                'localPath' => storage_path('app'),
                'localFree' => Installer::freeSpace(),
                'appUrl' => request()->getSchemeAndHttpHost(),
                'sqlitePath' => database_path('database.sqlite'),
                'form' => ['app_name' => config('app.name'), 'app_url' => request()->getSchemeAndHttpHost()],
            ],
        ]);
    }
}
