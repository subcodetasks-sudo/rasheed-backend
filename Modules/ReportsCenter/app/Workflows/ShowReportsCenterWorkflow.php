<?php

namespace Modules\ReportsCenter\Workflows;

use Modules\ReportsCenter\Actions\BuildReportsCenterAction;

class ShowReportsCenterWorkflow
{
    public function __construct(
        private readonly BuildReportsCenterAction $buildReportsCenterAction,
    ) {}

    /**
     * @param  array{period_type: string, start_date: string, end_date: string, month?: int, year?: int}  $period
     */
    public function handle(array $period): array
    {
        return $this->buildReportsCenterAction->execute($period);
    }
}
