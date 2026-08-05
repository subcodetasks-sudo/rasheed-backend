<?php

namespace Modules\OperationalFund\Actions;

use Carbon\CarbonInterface;
use Modules\Project\Actions\Project\ResolveEffectiveOperationalDeductionAction;
use Modules\Project\Actions\Project\SumFixedOperationalDeductionsAction;

class ResolveExpectedOperationalIncomeAction
{
    public function __construct(
        private readonly ResolveEffectiveOperationalDeductionAction $resolveEffectiveOperationalDeductionAction,
        private readonly SumFixedOperationalDeductionsAction $sumFixedOperationalDeductionsAction,
    ) {}

    public function execute(CarbonInterface|string $date): float
    {
        $base = $this->resolveEffectiveOperationalDeductionAction->execute($date);
        $fixedSum = $this->sumFixedOperationalDeductionsAction->execute();

        return round($base + $fixedSum, 2);
    }
}
