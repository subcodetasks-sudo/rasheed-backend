<?php

use Illuminate\Support\Facades\Route;
use Modules\AdministrationRates\Http\Controllers\V1\ShowAdministrationRatesController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('administration-rates', ShowAdministrationRatesController::class);
});
