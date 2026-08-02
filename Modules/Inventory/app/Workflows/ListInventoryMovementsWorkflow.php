<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\BuildInventoryMovementsSummaryAction;
use Modules\Inventory\Queries\ListInventoryMovementsQuery;

class ListInventoryMovementsWorkflow
{
    public function __construct(
        private readonly ListInventoryMovementsQuery $listInventoryMovementsQuery,
        private readonly BuildInventoryMovementsSummaryAction $buildInventoryMovementsSummaryAction,
    ) {}

    /**
     * @return array{movements: LengthAwarePaginator, summary: array{total_incoming_value: string, total_outgoing_value: string, net_movement_value: string}}
     */
    public function handle(Request $request): array
    {
        return [
            'movements' => $this->listInventoryMovementsQuery->paginate($request),
            'summary' => $this->buildInventoryMovementsSummaryAction->execute($request),
        ];
    }
}
