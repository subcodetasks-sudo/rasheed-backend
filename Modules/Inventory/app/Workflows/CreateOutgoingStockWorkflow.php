<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\CashStation\Actions\AssertFinancialMonthUnlockedAction;
use Modules\Inventory\Actions\CreateOutgoingStockAction;
use Modules\Inventory\DTOs\OutgoingStockData;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Inventory\Models\InventoryMovement;

class CreateOutgoingStockWorkflow
{
    public function __construct(
        private readonly CreateOutgoingStockAction $createOutgoingStockAction,
        private readonly AssertFinancialMonthUnlockedAction $assertFinancialMonthUnlockedAction,
    ) {}

    public function handle(OutgoingStockData $data): InventoryMovement
    {
        if ($data->expenseType === InventoryExpenseType::Administrative) {
            $this->assertFinancialMonthUnlockedAction->executeForDate(now());
        }

        $movement = DB::transaction(function () use ($data) {
            return $this->createOutgoingStockAction->execute(
                $data,
                auth()->user()?->uuid,
            );
        });

        InventoryStockMoved::dispatch($movement);

        return $movement;
    }
}
