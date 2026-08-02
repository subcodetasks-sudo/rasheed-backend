<?php

namespace Modules\Inventory\Actions;

use Illuminate\Http\Request;
use Modules\Inventory\Enums\InventoryMovementType;
use Modules\Inventory\Queries\ListInventoryMovementsQuery;

class BuildInventoryMovementsSummaryAction
{
    public function __construct(
        private readonly ListInventoryMovementsQuery $listInventoryMovementsQuery,
    ) {}

    /**
     * @return array{total_incoming_value: string, total_outgoing_value: string, net_movement_value: string}
     */
    public function execute(Request $request): array
    {
        $base = $this->listInventoryMovementsQuery->filteredQuery($request);
        $base->reorder()->setEagerLoads([]);

        $incoming = round((float) (clone $base)
            ->where('type', InventoryMovementType::Incoming)
            ->sum('total_cost'), 2);

        $outgoing = round((float) (clone $base)
            ->where('type', InventoryMovementType::Outgoing)
            ->sum('total_cost'), 2);

        return [
            'total_incoming_value' => number_format($incoming, 2, '.', ''),
            'total_outgoing_value' => number_format($outgoing, 2, '.', ''),
            'net_movement_value' => number_format(round($incoming - $outgoing, 2), 2, '.', ''),
        ];
    }
}
