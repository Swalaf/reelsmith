<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * Serves a Claude Design page (see design/import.py) as a Laravel response.
 *
 * The page is assembled from the imported template, the design's logic class, and the
 * Laravel integration subclass for that page, with server data injected as window.RS.
 * It is rendered by public/support.js exactly as in Claude Design.
 */
class DesignPage
{
    public static function make(string $page, string $title, array $boot = [], int $status = 200): Response
    {
        $dir = resource_path('designs');
        $read = fn (string $f) => file_get_contents($dir.'/'.$f);

        $boot = array_merge([
            'page' => $page,
            'csrf' => csrf_token(),
            'appName' => Branding::appName(),
            'supportEmail' => Branding::whiteLabel()['support'] ?? null,
            'user' => auth()->user() ? Boot::me(auth()->user()) : null,
        ], $boot);

        $json = json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $props = htmlspecialchars(json_encode(json_decode($read("$page.props.json"), true)), ENT_QUOTES);
        $safeTitle = e($title);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{$boot['csrf']}">
<title>{$safeTitle}</title>
<script>window.RS = {$json};</script>
<script>{$read('integration/_runtime.js')}</script>
<script src="/support.js"></script>
</head>
<body>
<x-dc>
{$read("$page.template.html")}
</x-dc>
<script type="text/x-dc" data-dc-script data-props="{$props}">
{$read("$page.logic.js")}
{$read("integration/$page.js")}
</script>
</body>
</html>
HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
