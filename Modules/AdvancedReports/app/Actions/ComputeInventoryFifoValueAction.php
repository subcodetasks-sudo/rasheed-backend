<?php

namespace Modules\AdvancedReports\Actions;

use Illuminate\Support\Facades\DB;

class ComputeInventoryFifoValueAction
{
    public function execute(): float
    {
        $value = (float) DB::table('inventory_batches')
            ->where('remaining_quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(remaining_quantity * unit_cost), 0) as total')
            ->value('total');

        return round($value, 2);
    }
}
