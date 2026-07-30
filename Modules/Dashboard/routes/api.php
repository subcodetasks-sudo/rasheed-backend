<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\V1\ShowDashboardController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('dashboard', ShowDashboardController::class);
});
