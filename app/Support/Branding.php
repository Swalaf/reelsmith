<?php

namespace App\Support;

use App\Models\Setting;

class Branding
{
    public const DEFAULTS = [
        'appName' => 'Reelsmith', 'company' => 'Reelsmith', 'domain' => 'localhost', 'primary' => '#2f6fed',
        'secondary' => '#0f1b33', 'loginHeadline' => 'Publish a week of video content in one afternoon.',
        'support' => 'support@example.com', 'website' => 'https://example.com', 'footer' => '© Reelsmith · Terms · Privacy',
        'emailHeader' => '#0f1b33', 'emailFooter' => 'You receive this because you have an account.', 'twitter' => '',
        'linkedin' => '', 'favicon' => 'favicon.ico', 'logo' => 'logo.svg', 'termVideo' => 'Video', 'termCredits' => 'Credits',
        'termWorkflows' => 'Automations', 'termWorkspace' => 'Studio', 'font' => 'Geist',
    ];

    public static function whiteLabel(): array
    {
        return array_merge(self::DEFAULTS, ['appName' => config('app.name')], (array) Setting::get('whitelabel', []));
    }

    public static function appName(): string
    {
        return (string) (self::whiteLabel()['appName'] ?: config('app.name'));
    }
}
