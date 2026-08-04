<?php

use Illuminate\Support\Facades\Route;
use Modules\CashFundExpenses\Http\Controllers\V1\ShowCashFundExpensesController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('cash-fund-expenses', ShowCashFundExpensesController::class);
});
