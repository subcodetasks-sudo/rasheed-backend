<?php

use Illuminate\Support\Facades\Route;
use Modules\OperationalFund\Http\Controllers\V1\ShowOperationalFundDayController;
use Modules\OperationalFund\Http\Controllers\V1\ShowOperationalFundMonthController;
use Modules\OperationalFund\Http\Controllers\V1\UpdateOperationalFundDayController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('operational-fund/day', ShowOperationalFundDayController::class);
    Route::get('operational-fund', ShowOperationalFundMonthController::class);
    Route::put('operational-fund/{date}', UpdateOperationalFundDayController::class)
        ->where('date', '\d{4}-\d{2}-\d{2}');
});
