<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Models\Project;
use Modules\Project\Services\AdministrativeDeductionService;
use Modules\Project\Services\OperationalDeductionService;

class CalculateProjectDeductionAction
{
    public function __construct(
        private readonly OperationalDeductionService $operationalDeductionService,
        private readonly AdministrativeDeductionService $administrativeDeductionService,
    ) {}

    /**
     * @param  array<int|string, float|int>  $relativeIncomes  incomes for relative projects used to compute total
     */
    public function execute(Project $project, float $income, array $relativeIncomes = []): array
    {
        $totalParticipatingIncome = 0.0;

        if ($project->hasRelativeOperationalDeduction()) {
            if ($relativeIncomes === []) {
                $relativeIncomes = [$project->id => $income];
            }

            $totalParticipatingIncome = (float) array_sum($relativeIncomes);
        }

        return [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'income' => $income,
            'operational_deduction_type' => $project->operational_deduction_type->value,
            'operational_deduction' => $this->operationalDeductionService->calculate(
                $project,
                $income,
                $totalParticipatingIncome
            ),
            'administrative_deduction' => $this->administrativeDeductionService->calculate($project, $income),
            'administrative_exempt' => $project->administrative_exempt,
        ];
    }
}
