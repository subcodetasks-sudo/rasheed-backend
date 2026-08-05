<?php

namespace Modules\OperationalRate\Workflows;

use Modules\OperationalRate\Actions\BuildOperationalRateAction;

class ShowOperationalRateWorkflow
{
    public function __construct(
        private readonly BuildOperationalRateAction $buildOperationalRateAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->buildOperationalRateAction->execute($month, $year);
    }
}
