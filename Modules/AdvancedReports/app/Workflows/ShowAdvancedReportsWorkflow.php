<?php

namespace Modules\AdvancedReports\Workflows;

use Modules\AdvancedReports\Actions\BuildInventoryReportAction;
use Modules\AdvancedReports\Actions\BuildMonthComparisonReportAction;
use Modules\Project\Exceptions\BusinessException;

class ShowAdvancedReportsWorkflow
{
    public function __construct(
        private readonly BuildMonthComparisonReportAction $buildMonthComparisonReportAction,
        private readonly BuildInventoryReportAction $buildInventoryReportAction,
    ) {}

    public function handle(string $reportType, int $period): array
    {
        return match ($reportType) {
            'month_comparison' => $this->buildMonthComparisonReportAction->execute($period),
            'inventory' => $this->buildInventoryReportAction->execute($period),
            default => throw new BusinessException(__('messages.advanced_reports_invalid_report_type'), 422),
        };
    }
}
