<?php

use Illuminate\Support\Facades\Route;
use Modules\AdministrativeFund\Http\Controllers\V1\ShowAdministrativeFundController;
use Modules\AdministrativeFund\Http\Controllers\V1\UpdateAdministrativeFundDayController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('administrative-fund', ShowAdministrativeFundController::class);
    Route::put('administrative-fund/{date}', UpdateAdministrativeFundDayController::class)
        ->where('date', '\d{4}-\d{2}-\d{2}');
});
