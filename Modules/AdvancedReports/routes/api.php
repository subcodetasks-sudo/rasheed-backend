<?php

use Illuminate\Support\Facades\Route;
use Modules\AdvancedReports\Http\Controllers\V1\ShowAdvancedReportsController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance|inventory'])->prefix('v1')->group(function () {
    Route::get('advanced-reports', ShowAdvancedReportsController::class);
});
