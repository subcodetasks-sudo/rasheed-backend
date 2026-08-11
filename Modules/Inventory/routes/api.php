<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\V1\CreateIncomingStockController;
use Modules\Inventory\Http\Controllers\V1\CreateInventoryCategoryController;
use Modules\Inventory\Http\Controllers\V1\CreateInventoryItemController;
use Modules\Inventory\Http\Controllers\V1\CreateOutgoingStockController;
use Modules\Inventory\Http\Controllers\V1\DeleteInventoryCategoryController;
use Modules\Inventory\Http\Controllers\V1\DeleteInventoryItemController;
use Modules\Inventory\Http\Controllers\V1\DeleteInventoryMovementController;
use Modules\Inventory\Http\Controllers\V1\EditInventoryCategoryController;
use Modules\Inventory\Http\Controllers\V1\ListInventoryCategoriesController;
use Modules\Inventory\Http\Controllers\V1\ListInventoryItemsController;
use Modules\Inventory\Http\Controllers\V1\ListInventoryMovementsController;
use Modules\Inventory\Http\Controllers\V1\ShowInventoryItemController;

Route::middleware(['auth:sanctum', 'role:super-admin|inventory'])->prefix('v1')->group(function () {
    Route::get('inventory/categories', ListInventoryCategoriesController::class);
    Route::post('inventory/categories', CreateInventoryCategoryController::class);
    Route::patch('inventory/categories/{category}', EditInventoryCategoryController::class);
    Route::delete('inventory/categories/{category}', DeleteInventoryCategoryController::class);

    Route::get('inventory/items', ListInventoryItemsController::class);
    Route::post('inventory/items', CreateInventoryItemController::class);
    Route::get('inventory/items/{item}', ShowInventoryItemController::class);
    Route::delete('inventory/items/{item}', DeleteInventoryItemController::class);

    Route::get('inventory/movements', ListInventoryMovementsController::class);
    Route::post('inventory/movements/incoming', CreateIncomingStockController::class);
    Route::post('inventory/movements/outgoing', CreateOutgoingStockController::class);
    Route::delete('inventory/movements/{movement}', DeleteInventoryMovementController::class);
});
