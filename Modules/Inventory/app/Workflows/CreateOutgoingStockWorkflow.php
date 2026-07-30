<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\CreateOutgoingStockAction;
use Modules\Inventory\DTOs\OutgoingStockData;
use Modules\Inventory\Models\InventoryMovement;

class CreateOutgoingStockWorkflow
{
    public function __construct(
        private readonly CreateOutgoingStockAction $createOutgoingStockAction,
    ) {}

    public function handle(OutgoingStockData $data): InventoryMovement
    {
        return DB::transaction(function () use ($data) {
            return $this->createOutgoingStockAction->execute(
                $data,
                auth()->user()?->uuid,
            );
        });
    }
}
