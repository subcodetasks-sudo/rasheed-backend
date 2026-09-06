<?php

use Illuminate\Support\Facades\Route;
use Modules\DailyJournal\Http\Controllers\V1\RepayAdministrativeDebtController;
use Modules\DailyJournal\Http\Controllers\V1\SaveDailyJournalController;
use Modules\DailyJournal\Http\Controllers\V1\ShowDailyJournalController;
use Modules\DailyJournal\Http\Controllers\V1\UpdateDailyJournalController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role:super-admin|finance')->group(function () {
        Route::get('daily-journals', ShowDailyJournalController::class);
    });

    Route::middleware('role:super-admin')->group(function () {
        Route::put('daily-journals', SaveDailyJournalController::class);
        Route::patch('daily-journals', UpdateDailyJournalController::class);
        Route::post('daily-journals/repay-debt', RepayAdministrativeDebtController::class);
    });
});
