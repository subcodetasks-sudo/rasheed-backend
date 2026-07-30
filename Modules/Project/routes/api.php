<?php

use Illuminate\Support\Facades\Route;
use Modules\Project\Http\Controllers\V1\ArchiveProjectController;
use Modules\Project\Http\Controllers\V1\CalculateDeductionsController;
use Modules\Project\Http\Controllers\V1\CalculateProjectDeductionController;
use Modules\Project\Http\Controllers\V1\CreateCategoryController;
use Modules\Project\Http\Controllers\V1\DeleteCategoryController;
use Modules\Project\Http\Controllers\V1\DeleteProjectController;
use Modules\Project\Http\Controllers\V1\EditCategoryController;
use Modules\Project\Http\Controllers\V1\ListCategoriesController;
use Modules\Project\Http\Controllers\V1\ListProjectsController;
use Modules\Project\Http\Controllers\V1\RestoreProjectController;
use Modules\Project\Http\Controllers\V1\ShowProjectController;
use Modules\Project\Http\Controllers\V1\ShowProjectFinancialSettingsController;
use Modules\Project\Http\Controllers\V1\StoreProjectController;
use Modules\Project\Http\Controllers\V1\UpdateProjectController;
use Modules\Project\Http\Controllers\V1\UpdateProjectFinancialSettingsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role:super-admin|finance')->group(function () {
        Route::get('projects/financial-settings', ShowProjectFinancialSettingsController::class);
        Route::post('projects/calculate-deductions', CalculateDeductionsController::class);
    });

    Route::middleware('role:super-admin')->group(function () {
        Route::patch('projects/financial-settings', UpdateProjectFinancialSettingsController::class);
        Route::post('projects', StoreProjectController::class);
        Route::post('categories', CreateCategoryController::class);
    });

    Route::middleware('role:super-admin|finance|inventory')->group(function () {
        Route::get('projects', ListProjectsController::class);
        Route::get('categories', ListCategoriesController::class);
    });

    Route::middleware('role:super-admin|finance')->group(function () {
        Route::post('projects/{project}/calculate-deduction', CalculateProjectDeductionController::class);
    });

    Route::middleware('role:super-admin|finance|inventory')->group(function () {
        Route::get('projects/{project}', ShowProjectController::class);
    });

    Route::middleware('role:super-admin')->group(function () {
        Route::patch('projects/{project}', UpdateProjectController::class);
        Route::delete('projects/{project}', DeleteProjectController::class);
        Route::post('projects/{project}/archive', ArchiveProjectController::class);
        Route::post('projects/{project}/restore', RestoreProjectController::class);
        Route::patch('categories/{category}', EditCategoryController::class);
        Route::delete('categories/{category}', DeleteCategoryController::class);
    });
});
