<?php

use App\Http\Controllers\Api\V1Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api.key')->group(function () {
    Route::post('/videos', [V1Controller::class, 'createVideo']);
    Route::get('/videos/{id}', [V1Controller::class, 'showVideo']);
    Route::get('/templates', [V1Controller::class, 'templates']);
    Route::post('/scripts', [V1Controller::class, 'scripts']);
    Route::get('/credits', [V1Controller::class, 'credits']);
});
