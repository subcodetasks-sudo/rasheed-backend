<?php

use Illuminate\Support\Facades\Route;
use Modules\ReportsCenter\Http\Controllers\V1\ShowReportsCenterController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('reports-center', ShowReportsCenterController::class);
});
