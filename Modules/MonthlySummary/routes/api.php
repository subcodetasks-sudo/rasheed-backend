<?php

use Illuminate\Support\Facades\Route;
use Modules\MonthlySummary\Http\Controllers\V1\CancelMonthlySummaryContributionController;
use Modules\MonthlySummary\Http\Controllers\V1\ListBeneficiaryOptionsController;
use Modules\MonthlySummary\Http\Controllers\V1\ListContributorOptionsController;
use Modules\MonthlySummary\Http\Controllers\V1\ShowMonthlySummaryController;
use Modules\MonthlySummary\Http\Controllers\V1\StoreMonthlySummaryContributionController;

Route::middleware(['auth:sanctum', 'role:super-admin|finance'])->prefix('v1')->group(function () {
    Route::get('monthly-summary', ShowMonthlySummaryController::class);
    Route::get('monthly-summary/contributor-options', ListContributorOptionsController::class);
    Route::get('monthly-summary/beneficiary-options', ListBeneficiaryOptionsController::class);
    Route::post('monthly-summary/contributions', StoreMonthlySummaryContributionController::class);
    Route::delete('monthly-summary/contributions/{settlement}', CancelMonthlySummaryContributionController::class);
});
