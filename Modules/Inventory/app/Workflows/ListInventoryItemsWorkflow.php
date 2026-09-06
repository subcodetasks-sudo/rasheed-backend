<?php

namespace Modules\Inventory\Workflows;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\BuildInventoryItemsOpeningSummaryAction;
use Modules\Inventory\Actions\BuildInventoryLowStockSummaryAction;
use Modules\Inventory\Queries\ListInventoryItemsQuery;

class ListInventoryItemsWorkflow
{
    public function __construct(
        private readonly ListInventoryItemsQuery $listInventoryItemsQuery,
        private readonly BuildInventoryItemsOpeningSummaryAction $buildInventoryItemsOpeningSummaryAction,
        private readonly BuildInventoryLowStockSummaryAction $buildInventoryLowStockSummaryAction,
    ) {}

    /**
     * @return array{
     *     items: LengthAwarePaginator,
     *     summary: array<string, mixed>
     * }
     */
    public function handle(Request $request): array
    {
        return [
            'items' => $this->listInventoryItemsQuery->paginate($request),
            'summary' => array_merge(
                $this->buildInventoryItemsOpeningSummaryAction->execute(),
                $this->buildInventoryLowStockSummaryAction->execute(),
            ),
        ];
    }
}
