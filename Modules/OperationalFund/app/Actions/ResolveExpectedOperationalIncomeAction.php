<?php

namespace Modules\OperationalFund\Actions;

use Carbon\CarbonInterface;
use Modules\Project\Actions\Project\ResolveEffectiveOperationalDeductionAction;

class ResolveExpectedOperationalIncomeAction
{
    public function __construct(
        private readonly ResolveEffectiveOperationalDeductionAction $resolveEffectiveOperationalDeductionAction,
    ) {}

    /**
     * Expected operational income for the day is the whole employee-cost budget — fixed-type
     * projects' flat charges are a carve-out of that same total, not an addition on top of it, so
     * this is just the effective pool value.
     */
    public function execute(CarbonInterface|string $date): float
    {
        return round($this->resolveEffectiveOperationalDeductionAction->execute($date), 2);
    }
}
