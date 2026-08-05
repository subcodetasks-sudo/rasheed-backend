<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Modules\Settings\app\Http\Controllers\V1\SettingController;
use Modules\Settings\Http\Controllers\V1\ShowMonthlyEmployeeSettingsController;
use Modules\Settings\Http\Controllers\V1\UpdateMonthlyEmployeeSettingsController;

Route::prefix('v1/settings')->middleware([SetLocale::class])->group(function () {
    Route::middleware(['auth:sanctum', 'role:super-admin'])->group(function () {
        Route::get('monthly-employees', ShowMonthlyEmployeeSettingsController::class)->middleware('rate_limit:user');
        Route::put('monthly-employees', UpdateMonthlyEmployeeSettingsController::class)->middleware('rate_limit:user');

        Route::get('/', [SettingController::class, 'index'])->middleware('rate_limit:user');
        Route::put('/', [SettingController::class, 'bulkUpdate'])->middleware('rate_limit:user');
        Route::post('{key}', [SettingController::class, 'update'])->middleware('rate_limit:user');
    });
});
