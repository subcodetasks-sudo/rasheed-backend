<?php

use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\RealtimeAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['auth:sanctum', 'CheckStatus'])->group(function () {
        Route::get('realtime/auth', RealtimeAuthController::class);
    });

    Route::prefix('media')->group(function () {
        Route::post('/', [MediaController::class, 'store']);
        Route::get('{id}', [MediaController::class, 'show']);
        Route::delete('{id}', [MediaController::class, 'destroy']);
        Route::get('{id}/download', [MediaController::class, 'download']);
    });
});
