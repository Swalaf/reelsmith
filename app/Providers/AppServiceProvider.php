<?php

namespace App\Providers;

use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureMailFromSettings();
    }

    /** Admin → Settings → Email (SMTP) overrides the .env mailer once a host is set. */
    private function configureMailFromSettings(): void
    {
        try {
            $host = Setting::value('set_email_smtp_smtp_host');
        } catch (\Throwable) {
            return; // not installed / no database yet
        }
        if (! $host) {
            return;
        }
        $port = (int) (Setting::value('set_email_smtp_port') ?: 587);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.scheme' => $port === 465 ? 'smtps' : null,
            'mail.mailers.smtp.username' => Setting::value('set_email_smtp_smtp_username'),
            'mail.mailers.smtp.password' => Setting::value('set_email_smtp_smtp_password'),
            'mail.from.address' => Setting::value('set_email_smtp_from_address', config('mail.from.address')),
            'mail.from.name' => Branding::appName(),
        ]);
    }
}
