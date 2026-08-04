<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\Project\Models\Project;

class ListContributorOptionsAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    /**
     * @return list<array{project_id: int, project_name: string, available_surplus: string}>
     */
    public function execute(int $month, int $year): array
    {
        $projects = Project::query()
            ->active()
            ->orderBy('id')
            ->get(['id', 'name']);

        $options = [];
        foreach ($projects as $project) {
            $surplus = $this->buildCashStationAction->transferableBalance($project->id, $month, $year);
            if ($surplus <= 0) {
                continue;
            }

            $options[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'available_surplus' => number_format($surplus, 2, '.', ''),
            ];
        }

        return $options;
    }
}
