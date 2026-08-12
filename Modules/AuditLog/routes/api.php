<?php

use Illuminate\Support\Facades\Route;
use Modules\AuditLog\Http\Controllers\V1\ListAuditLogsController;
use Modules\AuditLog\Http\Controllers\V1\ListMyActivityLogsController;

Route::middleware(['auth:sanctum', 'role:super-admin'])->prefix('v1')->group(function () {
    Route::get('audit-logs', ListAuditLogsController::class);
});

Route::middleware(['auth:sanctum', 'CheckStatus', 'role:super-admin|finance|inventory'])->prefix('v1')->group(function () {
    Route::get('my-activity-logs', ListMyActivityLogsController::class);
});
