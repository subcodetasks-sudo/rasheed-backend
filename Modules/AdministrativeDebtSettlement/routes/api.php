<?php

use Illuminate\Support\Facades\Route;
use Modules\AdministrativeDebtSettlement\Http\Controllers\V1\ListAdministrativeDebtSettlementsController;
use Modules\AdministrativeDebtSettlement\Http\Controllers\V1\StoreAdministrativeDebtSettlementController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('administrative-debt-settlements', ListAdministrativeDebtSettlementsController::class);
    Route::post('administrative-debt-settlements', StoreAdministrativeDebtSettlementController::class);
});
