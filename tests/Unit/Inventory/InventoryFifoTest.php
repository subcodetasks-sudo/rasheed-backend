<?php

namespace Tests\Unit\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Services\InventoryBalanceService;
use Modules\Inventory\Services\InventoryFifoService;
use Modules\Project\Models\Project;
use Tests\TestCase;

class InventoryFifoTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_formula(): void
    {
        $service = app(InventoryBalanceService::class);
        $item = new InventoryItem([
            'opening_quantity' => 10,
            'total_incoming_quantity' => 5,
            'total_outgoing_quantity' => 3,
        ]);

        $service->recompute($item);

        $this->assertSame(12.0, (float) $item->current_balance);
    }

    public function test_fifo_uses_batch_layers_not_latest_price(): void
    {
        $owner = Project::factory()->create();
        $beneficiary = Project::factory()->create();

        $item = InventoryItem::factory()->create([
            'project_id' => $owner->id,
            'opening_quantity' => 0,
            'total_incoming_quantity' => 0,
            'total_outgoing_quantity' => 0,
            'current_balance' => 0,
            'latest_incoming_price' => 0,
        ]);

        $fifo = app(InventoryFifoService::class);
        $fifo->createIncoming($item->fresh(), 10, 2, null, null);
        $fifo->createIncoming($item->fresh(), 10, 5, null, null);

        InventoryItem::query()->whereKey($item->id)->update(['latest_incoming_price' => 99]);

        $movement = $fifo->createOutgoing(
            $item->fresh(),
            12,
            $beneficiary->id,
            InventoryExpenseType::Operational,
            null,
            null,
        );

        $this->assertSame(30.0, (float) $movement->total_cost);
        $this->assertSame(2.5, (float) $movement->unit_price);
        $this->assertCount(2, $movement->consumptions);
    }
}
