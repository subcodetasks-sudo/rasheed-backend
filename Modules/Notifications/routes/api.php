<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\V1\ListNotificationsController;
use Modules\Notifications\Http\Controllers\V1\MarkAllNotificationsReadController;
use Modules\Notifications\Http\Controllers\V1\MarkNotificationReadController;
use Modules\Notifications\Http\Controllers\V1\ShowNotificationController;
use Modules\Notifications\Http\Controllers\V1\ShowNotificationStatisticsController;
use Modules\Notifications\Http\Controllers\V1\StreamNotificationsController;

// Route::prefix('v1')->middleware([
//     SetLocale::class,
//     'auth:sanctum',
//     'CheckStatus',
//     'role:super-admin|finance|inventory',
// ])->group(function () {
//     Route::get('notifications/statistics', ShowNotificationStatisticsController::class);
//     Route::get('notifications/stream', StreamNotificationsController::class);
//     Route::post('notifications/read-all', MarkAllNotificationsReadController::class);
//     Route::post('notifications/{notification}/read', MarkNotificationReadController::class);
//     Route::get('notifications/{notification}', ShowNotificationController::class);
//     Route::get('notifications', ListNotificationsController::class);
// });
