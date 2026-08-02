<?php

use Illuminate\Support\Facades\Route;
use Modules\CashStation\Http\Controllers\V1\CarryForwardCashStationController;
use Modules\CashStation\Http\Controllers\V1\DeleteCashStationSettlementController;
use Modules\CashStation\Http\Controllers\V1\ShowCashStationController;
use Modules\CashStation\Http\Controllers\V1\StoreCashStationSettlementController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('cash-station', ShowCashStationController::class);
    Route::post('cash-station/carry-forward', CarryForwardCashStationController::class);
    Route::post('cash-station/settlements', StoreCashStationSettlementController::class);
    Route::delete('cash-station/settlements/{settlement}', DeleteCashStationSettlementController::class);
});
