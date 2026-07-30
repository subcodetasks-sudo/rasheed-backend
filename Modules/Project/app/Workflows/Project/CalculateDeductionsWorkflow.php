<?php

namespace Modules\Project\Workflows\Project;

use Modules\Project\Actions\Project\CalculateDeductionsAction;

class CalculateDeductionsWorkflow
{
    public function __construct(
        private readonly CalculateDeductionsAction $calculateDeductionsAction,
    ) {}

    /**
     * @param  array<int|string, float|int>  $incomes
     */
    public function handle(array $incomes): array
    {
        return $this->calculateDeductionsAction->execute($incomes);
    }
}
