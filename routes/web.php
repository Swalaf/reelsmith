<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PageController;
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
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [AuthController::class, 'forgot']);
    Route::post('/reset-password', [AuthController::class, 'reset']);
    Route::post('/contact', [SiteController::class, 'contact']);
});
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/checkout', [SiteController::class, 'checkout']);
    Route::post('/onboarding', [SiteController::class, 'onboarding']);

    Route::get('/studio/{screen?}', [StudioController::class, 'show'])->where('screen', '[a-z]+');
    Route::get('/platform/{screen?}', [StudioController::class, 'platform'])->where('screen', '[a-z]+');
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
        Route::post('/providers/{provider:slug}/test', [StudioController::class, 'testProvider'])->middleware('throttle:30,1');
    });

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/{screen?}', [AdminController::class, 'show'])->where('screen', '[a-z]+');
        Route::put('/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
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
