<?php

namespace Tests\Support\RashidWorkbook;

/**
 * Pure-PHP, DB-free reimplementation of
 * Modules\Inventory\Services\InventoryFifoService's batch-consumption math
 * (same per-step rounding), replayed from the raw opening-batch + movement
 * fixture rows in the exact order they were seeded, so its internal
 * remaining-quantity state stays in lockstep with the real system's.
 */
class ExpectedFifoCalculator
{
    /** @var array<string, list<array{qty: float, unit_cost: float}>> item code => FIFO batch queue */
    private array $batches = [];

    public function seedOpeningBatch(string $itemCode, float $quantity, float $unitCost): void
    {
        $this->addBatch($itemCode, $quantity, $unitCost);
    }

    public function addIncoming(string $itemCode, float $quantity, float $unitCost): void
    {
        $this->addBatch($itemCode, $quantity, $unitCost);
    }

    private function addBatch(string $itemCode, float $quantity, float $unitCost): void
    {
        $quantity = round($quantity, 2);
        if ($quantity <= 0) {
            return;
        }

        $this->batches[$itemCode][] = ['qty' => $quantity, 'unit_cost' => round($unitCost, 2)];
    }

    /**
     * @return array{total_cost: float, consumptions: list<array{quantity: float, unit_cost: float, line_cost: float}>}
     */
    public function consumeOutgoing(string $itemCode, float $quantity): array
    {
        $remaining = round($quantity, 2);
        $totalCost = 0.0;
        $consumptions = [];

        // NOTE: `??=` first, then foreach by reference over the plain array
        // access - `foreach ($this->batches[$itemCode] ?? [] as &$batch)`
        // would silently reference a *temporary* copy (the `??` operator
        // breaks reference binding), so mutations would never persist back
        // onto $this->batches.
        $this->batches[$itemCode] ??= [];

        foreach ($this->batches[$itemCode] as &$batch) {
            if ($remaining <= 0) {
                break;
            }
            if ($batch['qty'] <= 0) {
                continue;
            }

            $take = round(min($batch['qty'], $remaining), 2);
            $lineCost = round($take * $batch['unit_cost'], 2);

            $batch['qty'] = round($batch['qty'] - $take, 2);

            $consumptions[] = ['quantity' => $take, 'unit_cost' => $batch['unit_cost'], 'line_cost' => $lineCost];
            $totalCost = round($totalCost + $lineCost, 2);
            $remaining = round($remaining - $take, 2);
        }
        unset($batch);

        return ['total_cost' => $totalCost, 'consumptions' => $consumptions];
    }
}
