<?php

namespace Modules\OperationalFund\Workflows;

use Modules\OperationalFund\Actions\BuildOperationalFundDayAction;

class ShowOperationalFundDayWorkflow
{
    public function __construct(
        private readonly BuildOperationalFundDayAction $buildOperationalFundDayAction,
    ) {}

    public function handle(string $date): array
    {
        return $this->buildOperationalFundDayAction->execute($date);
    }
}
