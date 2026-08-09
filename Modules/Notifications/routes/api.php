<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\V1\ListNotificationsController;
use Modules\Notifications\Http\Controllers\V1\ShowNotificationStatisticsController;
use Modules\Notifications\Http\Controllers\V1\StreamNotificationsController;

Route::prefix('v1')->middleware([
    SetLocale::class,
    'auth:sanctum',
    'CheckStatus',
    'role:super-admin|finance|inventory',
])->group(function () {
    Route::get('notifications/statistics', ShowNotificationStatisticsController::class);
    Route::get('notifications/stream', StreamNotificationsController::class);
    Route::get('notifications', ListNotificationsController::class);
});
