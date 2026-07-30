<?php

namespace Modules\Project\Actions\Project;

use Illuminate\Support\Collection;
use Modules\Project\Models\Project;
use Modules\Project\Services\AdministrativeDeductionService;
use Modules\Project\Services\OperationalDeductionService;

class CalculateDeductionsAction
{
    public function __construct(
        private readonly OperationalDeductionService $operationalDeductionService,
        private readonly AdministrativeDeductionService $administrativeDeductionService,
    ) {}

    /**
     * @param  array<int|string, float|int>  $incomes
     */
    public function execute(array $incomes): array
    {
        $projectIds = array_map('intval', array_keys($incomes));

        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->with('category')
            ->whereIn('id', $projectIds)
            ->get()
            ->keyBy('id');

        $operational = $this->operationalDeductionService->distribute($projects, $incomes);

        $results = [];

        foreach ($projects as $project) {
            $income = (float) ($incomes[$project->id] ?? 0);

            $results[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'income' => $income,
                'operational_deduction_type' => $project->operational_deduction_type->value,
                'operational_deduction' => $operational[$project->id] ?? 0.0,
                'administrative_deduction' => $this->administrativeDeductionService->calculate($project, $income),
                'administrative_exempt' => $project->administrative_exempt,
            ];
        }

        return $results;
    }
}
