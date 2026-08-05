<?php

namespace Modules\OperationalFund\Workflows;

use Modules\OperationalFund\Actions\BuildOperationalFundMonthAction;

class ShowOperationalFundMonthWorkflow
{
    public function __construct(
        private readonly BuildOperationalFundMonthAction $buildOperationalFundMonthAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->buildOperationalFundMonthAction->execute($month, $year);
    }
}
