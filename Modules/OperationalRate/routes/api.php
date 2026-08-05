<?php

use Illuminate\Support\Facades\Route;
use Modules\OperationalRate\Http\Controllers\V1\ShowOperationalRateController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('operational-rate', ShowOperationalRateController::class);
});
