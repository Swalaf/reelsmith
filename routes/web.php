<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StudioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Web installer (only reachable until installation completes — see EnsureInstalled)
Route::get('/install', [PageController::class, 'install']);
Route::prefix('install')->middleware('throttle:60,1')->group(function () {
    Route::post('/requirements', [InstallController::class, 'requirements']);
    Route::post('/database', [InstallController::class, 'database']);
    Route::post('/provider', [InstallController::class, 'provider']);
    Route::post('/cron', [InstallController::class, 'cron']);
    Route::post('/run', [InstallController::class, 'run']);
});

// Marketing site + auth screens (one design page; the server picks the start screen)
$site = fn (string $page) => fn (Request $r, ?string $arg = null) => app(PageController::class)->site($r, $page, $arg);
Route::get('/', $site('home'));
foreach (['features', 'pricing', 'providers', 'templates', 'docs', 'contact', 'about', 'changelog', 'checkout', 'onboarding', 'maintenance', 'suspended'] as $p) {
    Route::get('/'.$p, $site($p));
}
Route::get('/legal/{arg?}', $site('legal'));
Route::get('/login', $site('login'))->name('login');
Route::get('/register', $site('register'));
Route::get('/forgot-password', $site('forgot'));
Route::get('/reset-password/{arg}', $site('reset'))->name('password.reset');
Route::get('/verify', $site('verify'));
Route::get('/two-factor', $site('twofa'));

Route::middleware('throttle:20,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/two-factor', [AuthController::class, 'twoFactor']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [AuthController::class, 'forgot']);
    Route::post('/reset-password', [AuthController::class, 'reset']);
    Route::post('/contact', [SiteController::class, 'contact']);
});
Route::post('/logout', [AuthController::class, 'logout']);
Route::post('/webhooks/stripe', [SiteController::class, 'stripeWebhook']);
Route::post('/webhooks/razorpay', [SiteController::class, 'razorpayWebhook']);
Route::post('/webhooks/paystack', [SiteController::class, 'paystackWebhook']);
Route::post('/hooks/in/{token}', [PlatformController::class, 'incoming'])->middleware('throttle:60,1');

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/checkout', [SiteController::class, 'checkout']);
    Route::get('/checkout/return', [SiteController::class, 'checkoutReturn']);
    Route::post('/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/verify/resend', [AuthController::class, 'resend'])->middleware('throttle:3,1');
    Route::post('/onboarding', [SiteController::class, 'onboarding']);

    Route::get('/studio/{screen?}', [StudioController::class, 'show'])->where('screen', '[a-z]+');
    Route::get('/platform/{screen?}', [PlatformController::class, 'show'])->where('screen', '[a-z]+');
    Route::prefix('platform/api')->group(function () {
        Route::get('/state', [PlatformController::class, 'state']);
        Route::post('/workflows', [PlatformController::class, 'createWorkflow']);
        Route::put('/workflows/{workflow}', [PlatformController::class, 'updateWorkflow']);
        Route::delete('/workflows/{workflow}', [PlatformController::class, 'deleteWorkflow']);
        Route::post('/workflows/{workflow}/run', [PlatformController::class, 'runWorkflow'])->middleware('throttle:20,1');
        Route::get('/runs/{run}', [PlatformController::class, 'showRun']);
        Route::post('/runs/{run}/retry', [PlatformController::class, 'retryRun'])->middleware('throttle:20,1');
        Route::post('/briefs', [PlatformController::class, 'brief'])->middleware('throttle:20,1');
        Route::post('/characters', [PlatformController::class, 'saveCharacter']);
        Route::put('/characters/{character}', [PlatformController::class, 'saveCharacter']);
        Route::delete('/characters/{character}', [PlatformController::class, 'deleteCharacter']);
        Route::post('/characters/{character}/image', [PlatformController::class, 'characterImage'])->middleware('throttle:20,1');
        Route::put('/production', [PlatformController::class, 'saveProduction']);
        Route::post('/production/shots/{shot}', [PlatformController::class, 'generateShot'])->middleware('throttle:30,1');
        Route::post('/production/assemble', [PlatformController::class, 'assemble']);
        Route::post('/production/rewrite', [PlatformController::class, 'rewrite'])->middleware('throttle:10,1');
        Route::post('/agents', [PlatformController::class, 'saveAgent']);
        Route::put('/agents/{agent}', [PlatformController::class, 'saveAgent']);
        Route::delete('/agents/{agent}', [PlatformController::class, 'deleteAgent']);
        Route::post('/agents/{agent}/run', [PlatformController::class, 'runAgent'])->middleware('throttle:20,1');
        Route::post('/repurpose', [PlatformController::class, 'repurpose'])->middleware('throttle:10,1');
        Route::post('/webhooks', [PlatformController::class, 'addWebhook']);
        Route::post('/webhooks/{webhook}/test', [PlatformController::class, 'testWebhook'])->middleware('throttle:10,1');
        Route::delete('/webhooks/{webhook}', [PlatformController::class, 'deleteWebhook']);
    });
    Route::prefix('studio')->group(function () {
        Route::post('/projects', [StudioController::class, 'store']);
        Route::get('/projects/{project}', [StudioController::class, 'project']);
        Route::put('/projects/{project}', [StudioController::class, 'update']);
        Route::delete('/projects/{project}', [StudioController::class, 'destroy']);
        Route::post('/projects/{project}/script', [StudioController::class, 'regenerate']);
        Route::post('/projects/{project}/render', [StudioController::class, 'render']);
        Route::post('/projects/{project}/scenes/{scene}/visual', [StudioController::class, 'sceneVisual'])->middleware('throttle:30,1');
        Route::post('/projects/{project}/scenes/{scene}/upload', [StudioController::class, 'sceneUpload']);
        Route::get('/media', [StudioController::class, 'media']);
        Route::post('/voices/preview', [StudioController::class, 'voicePreview'])->middleware('throttle:20,1');
        Route::put('/brand', [StudioController::class, 'brand']);
        Route::get('/account', [AccountController::class, 'state']);
        Route::post('/media', [AccountController::class, 'upload']);
        Route::delete('/media/{asset}', [AccountController::class, 'deleteMedia']);
        Route::post('/api-keys', [AccountController::class, 'createKey']);
        Route::delete('/api-keys/{key}', [AccountController::class, 'revokeKey']);
        Route::put('/account/profile', [AccountController::class, 'profile']);
        Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:10,1');
        Route::put('/account/prefs', [AccountController::class, 'prefs']);
        Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:5,1');
        Route::post('/account/2fa/setup', [AccountController::class, 'twoFactorSetup']);
        Route::post('/account/2fa/confirm', [AccountController::class, 'twoFactorConfirm'])->middleware('throttle:10,1');
        Route::post('/account/2fa/recovery', [AccountController::class, 'twoFactorRecovery'])->middleware('throttle:5,1');
        Route::delete('/account/2fa', [AccountController::class, 'twoFactorDisable'])->middleware('throttle:5,1');
        Route::post('/tickets', [AccountController::class, 'openTicket'])->middleware('throttle:10,1');
        Route::post('/tickets/{ticket}/reply', [AccountController::class, 'replyTicket'])->middleware('throttle:20,1');
        Route::post('/tickets/{ticket}/close', [AccountController::class, 'closeTicket']);
        Route::post('/providers/{provider:slug}/test', [StudioController::class, 'testProvider'])->middleware('throttle:30,1');
    });

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/{screen?}', [AdminController::class, 'show'])->where('screen', '[a-z]+');
        Route::put('/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
        Route::post('/payments/{payment:reference}/settle', [AdminController::class, 'settlePayment']);
        Route::post('/users/{user}/credits', [AdminController::class, 'credits']);
        Route::post('/users/{user}/impersonate', [AdminController::class, 'impersonate']);
        Route::delete('/projects/{project}', [AdminController::class, 'deleteProject']);
        Route::post('/providers', [AdminController::class, 'addProvider']);
        Route::put('/providers/{provider:slug}', [AdminController::class, 'updateProvider']);
        Route::post('/providers/{provider:slug}/toggle', [AdminController::class, 'toggleProvider']);
        Route::post('/templates/{template}/duplicate', [AdminController::class, 'duplicateTemplate']);
        Route::delete('/templates/{template}', [AdminController::class, 'deleteTemplate']);
        Route::post('/plans', [AdminController::class, 'storePlan']);
        Route::put('/plans/{plan}', [AdminController::class, 'updatePlan']);
        Route::delete('/plans/{plan}', [AdminController::class, 'deletePlan']);
        Route::post('/api-keys', [AdminController::class, 'createKey']);
        Route::delete('/api-keys/{apiKey}', [AdminController::class, 'revokeKey']);
        Route::put('/whitelabel', [AdminController::class, 'whitelabel']);
        Route::put('/settings', [AdminController::class, 'settings']);
        Route::put('/pages', [AdminController::class, 'pages']);
    });
});

Route::fallback($site('e404'));
