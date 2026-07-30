<?php

use App\Http\Controllers\Api\MediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/media')->group(function () {
    Route::post('/', [MediaController::class, 'store']);
    Route::get('{id}', [MediaController::class, 'show']);
    Route::delete('{id}', [MediaController::class, 'destroy']);
    Route::get('{id}/download', [MediaController::class, 'download']);
});
