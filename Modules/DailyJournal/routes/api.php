<?php

use Illuminate\Support\Facades\Route;
use Modules\DailyJournal\Http\Controllers\V1\RepayAdministrativeDebtController;
use Modules\DailyJournal\Http\Controllers\V1\SaveDailyJournalController;
use Modules\DailyJournal\Http\Controllers\V1\ShowDailyJournalController;
use Modules\DailyJournal\Http\Controllers\V1\UpdateDailyJournalController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('daily-journals', ShowDailyJournalController::class);
    Route::put('daily-journals', SaveDailyJournalController::class);
    Route::patch('daily-journals', UpdateDailyJournalController::class);
    Route::post('daily-journals/repay-debt', RepayAdministrativeDebtController::class);
});
