<?php

namespace Modules\MonthlySummary\Actions;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\CashStation\Models\CashStationSettlement;
use Modules\Project\Models\Project;

class BuildMonthlySummaryAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
    ) {}

    public function execute(int $month, int $year): array
    {
        [$start, $end, $calculationDate] = $this->calculationWindow($month, $year);

        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->active()
            ->with('category')
            ->orderBy('id')
            ->get(['id', 'name', 'category_id', 'administrative_exempt']);

        $projectIds = $projects->pluck('id')->all();

        $aggregates = $this->buildCashStationAction->monthlyAggregatesByProject(
            $projectIds,
            $start,
            $end,
        );

        $debts = $this->buildCashStationAction->administrativeDebtsByProject($projectIds, $end);
        $settlements = $this->buildCashStationAction->settlementsForMonth($year, $month);
        $contributions = $this->buildCashStationAction->contributionsByProject($settlements);

        $projectRows = [];
        foreach ($projects as $project) {
            $monthlyTotal = $this->buildCashStationAction->monthlyTotalFromAggregate(
                $aggregates[$project->id] ?? null
            );
            $added = (float) ($contributions[$project->id]['added'] ?? 0);
            $deducted = (float) ($contributions[$project->id]['deducted'] ?? 0);
            $debt = (float) ($debts[$project->id] ?? 0);
            $netResult = $monthlyTotal + $added - $deducted;

            $projectRows[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'project_type' => $project->category?->name,
                'project_status' => $project->administrative_exempt
                    ? 'exempt'
                    : 'subject',
                'project_net_result' => $this->decimal($netResult),
                'net_result_state' => $this->netResultState($netResult),
                'administrative_debt' => $this->decimal($debt),
                'total_received_contributions' => $this->decimal($added),
                'total_deducted_contributions' => $this->decimal($deducted),
            ];
        }

        return [
            'month' => ['month' => $month, 'year' => $year],
            'calculation_date' => $calculationDate,
            'projects' => $projectRows,
            'contributions' => $settlements
                ->filter(fn (CashStationSettlement $s) => $s->contribution_type !== null)
                ->map(fn (CashStationSettlement $settlement) => [
                    'id' => $settlement->id,
                    'year' => $settlement->year,
                    'month' => $settlement->month,
                    'from_project_id' => $settlement->from_project_id,
                    'to_project_id' => $settlement->to_project_id,
                    'contribution_type' => $settlement->contribution_type->value,
                    'amount' => $this->decimal($settlement->amount),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string} start, end, calculation_date
     */
    public function calculationWindow(int $month, int $year): array
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->startOfDay();
        $today = Carbon::today()->startOfDay();

        $end = $endOfMonth;
        if ($today->year === $year && $today->month === $month && $today->lt($endOfMonth)) {
            $end = $today->copy();
        }

        return [
            $startOfMonth->toDateString(),
            $end->toDateString(),
            $end->toDateString(),
        ];
    }

    private function netResultState(float $monthlyTotal): string
    {
        if ($monthlyTotal > 0) {
            return 'surplus';
        }

        if ($monthlyTotal < 0) {
            return 'deficit';
        }

        return 'neutral';
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
