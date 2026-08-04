<?php

namespace Modules\MonthlySummary\Workflows;

use Modules\MonthlySummary\Actions\BuildMonthlySummaryAction;

class ShowMonthlySummaryWorkflow
{
    public function __construct(
        private readonly BuildMonthlySummaryAction $buildMonthlySummaryAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->buildMonthlySummaryAction->execute($month, $year);
    }
}
