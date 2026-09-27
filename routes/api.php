<?php

use App\Http\Controllers\Api\V1Controller;
use Illuminate\Support\Facades\Route;

// Endpoints as documented on the AI Platform → API & Webhooks screen.
Route::middleware('api.key')->group(function () {
    Route::post('/video/generate', [V1Controller::class, 'createVideo']);
    Route::post('/workflows/run', [V1Controller::class, 'runWorkflow']);
    Route::get('/jobs/{id}', [V1Controller::class, 'job']);
    Route::post('/images/generate', [V1Controller::class, 'images']);
    Route::post('/agents/{agent}/run', [V1Controller::class, 'runAgent']);
});

Route::prefix('v1')->middleware('api.key')->group(function () {
    Route::post('/videos', [V1Controller::class, 'createVideo']);
    Route::get('/videos/{id}', [V1Controller::class, 'showVideo']);
    Route::get('/templates', [V1Controller::class, 'templates']);
    Route::post('/scripts', [V1Controller::class, 'scripts']);
    Route::get('/credits', [V1Controller::class, 'credits']);
});
