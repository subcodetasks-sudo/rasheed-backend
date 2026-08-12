<?php

use Illuminate\Support\Facades\Route;
use Modules\AuditLog\Http\Controllers\V1\ListAuditLogsController;

Route::middleware(['auth:sanctum', 'role:super-admin'])->prefix('v1')->group(function () {
    Route::get('audit-logs', ListAuditLogsController::class);
});
