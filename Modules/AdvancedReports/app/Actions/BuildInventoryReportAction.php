<?php

namespace Modules\AdvancedReports\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Inventory\Models\InventoryItem;

class BuildInventoryReportAction
{
    public function __construct(
        private readonly ResolveAdvancedReportPeriodAction $resolveAdvancedReportPeriodAction,
        private readonly ComputeInventoryFifoValueAction $computeInventoryFifoValueAction,
    ) {}

    public function execute(int $period): array
    {
        $range = $this->resolveAdvancedReportPeriodAction->dateRange($period);

        $items = InventoryItem::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'unit',
                'opening_quantity',
                'total_incoming_quantity',
                'total_outgoing_quantity',
                'current_balance',
                'minimum_stock_level',
            ]);

        $rows = [];
        $lowStockCount = 0;

        foreach ($items as $item) {
            $incoming = round(
                (float) $item->opening_quantity + (float) $item->total_incoming_quantity,
                2,
            );
            $outgoing = round((float) $item->total_outgoing_quantity, 2);
            $balance = round((float) $item->current_balance, 2);
            $minimum = round((float) $item->minimum_stock_level, 2);
            $status = $this->resolveStatus($balance, $minimum);

            if ($status === 'low') {
                $lowStockCount++;
            }

            $rows[] = [
                'item_id' => (int) $item->id,
                'item_name' => (string) $item->name,
                'unit' => (string) $item->unit,
                'total_incoming' => FormatMoneyDecimal::formatRounded($incoming),
                'total_outgoing' => FormatMoneyDecimal::formatRounded($outgoing),
                'balance' => FormatMoneyDecimal::formatRounded($balance),
                'minimum_stock' => $this->decimal($minimum),
                'status' => $status,
            ];
        }

        return [
            'report_type' => 'inventory',
            'period' => $period,
            'summary' => [
                'total_items' => $items->count(),
                'low_stock_items' => $lowStockCount,
                'inventory_value' => FormatMoneyDecimal::formatRounded($this->computeInventoryFifoValueAction->execute()),
                'most_consumed_item' => $this->mostConsumedItem(
                    $range['start_date'],
                    $range['end_bound'],
                ),
            ],
            'items' => $rows,
        ];
    }

    private function resolveStatus(float $balance, float $minimum): string
    {
        if ($balance <= 0) {
            return 'out_of_stock';
        }

        if ($balance <= $minimum) {
            return 'low';
        }

        return 'good';
    }

    /**
     * @return array{id: int, name: string, unit: string, quantity_consumed: string}|null
     */
    private function mostConsumedItem(string $startDate, string $endBound): ?array
    {
        $outgoing = InventoryMovementType::Outgoing->value;

        $row = DB::table('inventory_movements')
            ->join('inventory_items', 'inventory_movements.inventory_item_id', '=', 'inventory_items.id')
            ->where('inventory_movements.type', $outgoing)
            ->where('inventory_movements.movement_date', '>=', $startDate)
            ->where('inventory_movements.movement_date', '<=', $endBound)
            ->groupBy(
                'inventory_movements.inventory_item_id',
                'inventory_items.name',
                'inventory_items.unit',
            )
            ->orderByDesc(DB::raw('SUM(inventory_movements.quantity)'))
            ->orderBy('inventory_items.name')
            ->selectRaw('inventory_movements.inventory_item_id as id')
            ->selectRaw('inventory_items.name as name')
            ->selectRaw('inventory_items.unit as unit')
            ->selectRaw('COALESCE(SUM(inventory_movements.quantity), 0) as quantity_consumed')
            ->first();

        if ($row === null || (float) $row->quantity_consumed <= 0) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'unit' => (string) $row->unit,
            'quantity_consumed' => FormatMoneyDecimal::formatRounded($row->quantity_consumed),
        ];
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
