<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\BuildInventoryItemsOpeningSummaryAction;
use Modules\Inventory\Queries\ListInventoryItemsQuery;

class ListInventoryItemsWorkflow
{
    public function __construct(
        private readonly ListInventoryItemsQuery $listInventoryItemsQuery,
        private readonly BuildInventoryItemsOpeningSummaryAction $buildInventoryItemsOpeningSummaryAction,
    ) {}

    /**
     * @return array{
     *     items: LengthAwarePaginator,
     *     summary: array{total_opening_balance_value: string}
     * }
     */
    public function handle(Request $request): array
    {
        return [
            'items' => $this->listInventoryItemsQuery->paginate($request),
            'summary' => $this->buildInventoryItemsOpeningSummaryAction->execute(),
        ];
    }
}
