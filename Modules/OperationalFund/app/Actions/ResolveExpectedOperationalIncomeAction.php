<?php

namespace Modules\OperationalFund\Actions;

use Carbon\CarbonInterface;
use Modules\Project\Actions\Project\ResolveEffectiveOperationalDeductionAction;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\Project;

class ResolveExpectedOperationalIncomeAction
{
    public function __construct(
        private readonly ResolveEffectiveOperationalDeductionAction $resolveEffectiveOperationalDeductionAction,
    ) {}

    public function execute(CarbonInterface|string $date): float
    {
        $base = $this->resolveEffectiveOperationalDeductionAction->execute($date);

        $fixedSum = (float) Project::query()
            ->active()
            ->where('operational_deduction_type', OperationalDeductionType::Fixed)
            ->selectRaw('COALESCE(SUM(COALESCE(operational_fixed_amount, 0)), 0) as total')
            ->value('total');

        return round($base + $fixedSum, 2);
    }
}
