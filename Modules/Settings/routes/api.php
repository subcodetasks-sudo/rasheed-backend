<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\V1\ShowMonthlyEmployeeSettingsController;
use Modules\Settings\Http\Controllers\V1\ShowSystemGeneralSettingsController;
use Modules\Settings\Http\Controllers\V1\UpdateMonthlyEmployeeSettingsController;
use Modules\Settings\Http\Controllers\V1\UpdateSystemGeneralSettingsController;

Route::prefix('v1/settings')->middleware([SetLocale::class])->group(function () {
    Route::middleware(['auth:sanctum', 'role:super-admin'])->group(function () {
        Route::get('general', ShowSystemGeneralSettingsController::class)->middleware('rate_limit:user');
        Route::put('general', UpdateSystemGeneralSettingsController::class)->middleware('rate_limit:user');

        Route::get('monthly-employees', ShowMonthlyEmployeeSettingsController::class)->middleware('rate_limit:user');
        Route::put('monthly-employees', UpdateMonthlyEmployeeSettingsController::class)->middleware('rate_limit:user');
    });
});
