<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\CreateIncomingStockAction;
use Modules\Inventory\DTOs\IncomingStockData;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Inventory\Models\InventoryMovement;

class CreateIncomingStockWorkflow
{
    public function __construct(
        private readonly CreateIncomingStockAction $createIncomingStockAction,
    ) {}

    public function handle(IncomingStockData $data): InventoryMovement
    {
        $movement = DB::transaction(function () use ($data) {
            return $this->createIncomingStockAction->execute(
                $data,
                auth()->user()?->uuid,
            );
        });

        InventoryStockMoved::dispatch($movement);

        return $movement;
    }
}
