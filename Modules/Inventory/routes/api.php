<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\V1\CreateIncomingStockController;
use Modules\Inventory\Http\Controllers\V1\CreateInventoryItemController;
use Modules\Inventory\Http\Controllers\V1\CreateOutgoingStockController;
use Modules\Inventory\Http\Controllers\V1\ListInventoryItemsController;
use Modules\Inventory\Http\Controllers\V1\ListInventoryMovementsController;
use Modules\Inventory\Http\Controllers\V1\ShowInventoryItemController;

Route::middleware(['auth:sanctum', 'role:super-admin|inventory'])->prefix('v1')->group(function () {
    Route::get('inventory/items', ListInventoryItemsController::class);
    Route::post('inventory/items', CreateInventoryItemController::class);
    Route::get('inventory/items/{item}', ShowInventoryItemController::class);

    Route::get('inventory/movements', ListInventoryMovementsController::class);
    Route::post('inventory/movements/incoming', CreateIncomingStockController::class);
    Route::post('inventory/movements/outgoing', CreateOutgoingStockController::class);
});
