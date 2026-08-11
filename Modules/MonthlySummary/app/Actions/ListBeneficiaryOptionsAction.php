<?php

namespace Modules\MonthlySummary\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\Project\Exceptions\BusinessException;
use Modules\Project\Models\Project;

class ListBeneficiaryOptionsAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    /**
     * @return list<array{project_id: int, project_name: string, remaining_need: string}>
     */
    public function execute(
        int $month,
        int $year,
        int $fromProjectId,
        ContributionType $contributionType,
    ): array {
        $from = Project::query()->active()->find($fromProjectId);
        if ($from === null) {
            throw new BusinessException(__('messages.monthly_summary_contributor_inactive'));
        }

        $candidates = Project::query()
            ->active()
            ->where('category_id', $from->category_id)
            ->where('id', '!=', $fromProjectId)
            ->orderBy('id')
            ->get(['id', 'name', 'category_id']);

        $options = [];
        foreach ($candidates as $project) {
            $need = $this->remainingNeed($project->id, $month, $year, $contributionType);
            if ($need <= 0) {
                continue;
            }

            $options[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'remaining_need' => FormatMoneyDecimal::formatRounded($need),
            ];
        }

        return $options;
    }

    public function remainingNeed(
        int $projectId,
        int $month,
        int $year,
        ContributionType $contributionType,
    ): float {
        if ($contributionType === ContributionType::FundDeficit) {
            $netCash = $this->buildCashStationAction->netCashFundForProject($projectId, $month, $year);

            return round(abs(min(0.0, $netCash)), 2);
        }

        $end = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));
        $today = now()->toDateString();
        if ((int) now()->format('Y') === $year && (int) now()->format('n') === $month && $today < $end) {
            $end = $today;
        }

        $debts = $this->buildCashStationAction->administrativeDebtsByProject([$projectId], $end);

        return round(max(0.0, (float) ($debts[$projectId] ?? 0)), 2);
    }
}
