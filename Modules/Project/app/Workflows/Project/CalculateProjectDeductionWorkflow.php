<?php

namespace Modules\Project\Workflows\Project;

use Modules\Project\Actions\Project\CalculateProjectDeductionAction;
use Modules\Project\Models\Project;

class CalculateProjectDeductionWorkflow
{
    public function __construct(
        private readonly CalculateProjectDeductionAction $calculateProjectDeductionAction,
    ) {}

    /**
     * @param  array<int|string, float|int>  $relativeIncomes
     */
    public function handle(Project $project, float $income, array $relativeIncomes = []): array
    {
        return $this->calculateProjectDeductionAction->execute($project, $income, $relativeIncomes);
    }
}
