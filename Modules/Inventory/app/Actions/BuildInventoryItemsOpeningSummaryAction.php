<?php

namespace Modules\Inventory\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Modules\Inventory\Models\InventoryItem;

class BuildInventoryItemsOpeningSummaryAction
{
    /**
     * Sum of (opening_quantity × opening_price) across every item — independent of
     * current_balance, latest_incoming_price, and FIFO value.
     *
     * @return array{total_opening_balance_value: string}
     */
    public function execute(): array
    {
        $total = round(
            (float) InventoryItem::query()
                ->selectRaw('COALESCE(SUM(opening_quantity * opening_price), 0) as total')
                ->value('total'),
            2,
        );

        return [
            'total_opening_balance_value' => FormatMoneyDecimal::formatRounded($total),
        ];
    }
}
