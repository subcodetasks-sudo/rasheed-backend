<?php

namespace Modules\MonthlySummary\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Modules\DailyJournal\Actions\ReadFundBalanceTipAction;
use Modules\Project\Models\Project;

class ListContributorOptionsAction
{
    public function __construct(
        private readonly ReadFundBalanceTipAction $readFundBalanceTipAction,
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

        $end = $this->monthEnd($year, $month);
        $balances = $this->readFundBalanceTipAction->execute($projects->pluck('id')->all(), $end);

        $options = [];
        foreach ($projects as $project) {
            $surplus = round(max(0.0, (float) ($balances[$project->id] ?? 0)), 2);
            if ($surplus <= 0) {
                continue;
            }

            $options[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'available_surplus' => FormatMoneyDecimal::formatRounded($surplus),
            ];
        }

        return $options;
    }

    private function monthEnd(int $year, int $month): string
    {
        return sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            (int) date('t', mktime(0, 0, 0, $month, 1, $year)),
        );
    }
}
