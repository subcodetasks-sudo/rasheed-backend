<?php

namespace Modules\Project\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Project\Actions\Project\ResolveEffectiveOperationalDeductionAction;
use Modules\Project\Actions\Project\SumFixedOperationalDeductionsAction;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\Project;

class OperationalDeductionService
{
    public function __construct(
        private readonly ResolveEffectiveOperationalDeductionAction $resolveEffectiveOperationalDeductionAction,
        private readonly SumFixedOperationalDeductionsAction $sumFixedOperationalDeductionsAction,
    ) {}

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<int|string, float|int>  $incomes  keyed by project id
     * @return array<int, float>
     */
    public function distribute(
        Collection $projects,
        array $incomes,
        CarbonInterface|string|null $asOfDate = null,
    ): array {
        $participating = $projects->filter(
            fn (Project $project) => $project->isActive() && ! $project->isOperationalExempt()
        );

        $relativeProjects = $participating->filter(
            fn (Project $project) => $project->hasRelativeOperationalDeduction()
        );

        $totalParticipatingIncome = $relativeProjects->sum(
            fn (Project $project) => (float) ($incomes[$project->id] ?? 0)
        );

        $totalOperationalDeduction = $this->resolveRelativePool($asOfDate ?? Carbon::now());

        $deductions = [];

        foreach ($participating as $project) {
            $deductions[$project->id] = $this->calculate(
                $project,
                (float) ($incomes[$project->id] ?? 0),
                $totalParticipatingIncome,
                $totalOperationalDeduction,
                $asOfDate
            );
        }

        return $this->reconcileRelativeShares(
            $deductions,
            $relativeProjects,
            $incomes,
            $totalParticipatingIncome,
            $totalOperationalDeduction,
        );
    }

    /**
     * Relative shares are whole numbers, so independently rounded shares can drift from the pool by a
     * few units. Largest-remainder allocation keeps every share whole while the shares sum to the pool
     * exactly (ties go to the lower project id).
     *
     * @param  array<int, float>  $deductions
     * @param  Collection<int, Project>  $relativeProjects
     * @param  array<int|string, float|int>  $incomes
     * @return array<int, float>
     */
    private function reconcileRelativeShares(
        array $deductions,
        Collection $relativeProjects,
        array $incomes,
        float $totalParticipatingIncome,
        float $pool,
    ): array {
        if ($totalParticipatingIncome <= 0 || $pool <= 0 || $relativeProjects->isEmpty()) {
            return $deductions;
        }

        $floors = [];
        $fractions = [];

        foreach ($relativeProjects as $project) {
            $exact = ((float) ($incomes[$project->id] ?? 0) / $totalParticipatingIncome) * $pool;
            $floors[$project->id] = floor($exact);
            $fractions[$project->id] = $exact - floor($exact);
        }

        $remainder = (int) round(round($pool, 0) - array_sum($floors));

        $ids = array_keys($fractions);
        usort($ids, fn (int $a, int $b) => [$fractions[$b], $a] <=> [$fractions[$a], $b]);

        foreach ($ids as $position => $projectId) {
            $deductions[$projectId] = $floors[$projectId] + ($position < $remainder ? 1.0 : 0.0);
        }

        return $deductions;
    }

    public function calculate(
        Project $project,
        float $projectIncome,
        float $totalParticipatingIncome = 0,
        ?float $totalOperationalDeduction = null,
        CarbonInterface|string|null $asOfDate = null,
    ): float {
        $pool = $totalOperationalDeduction ?? $this->resolveRelativePool($asOfDate ?? Carbon::now());

        return match ($project->operational_deduction_type) {
            OperationalDeductionType::Relative => $totalParticipatingIncome > 0
                ? round(($projectIncome / $totalParticipatingIncome) * $pool, 0)
                : 0.0,
            OperationalDeductionType::Fixed => (float) ($project->operational_fixed_amount ?? 0),
            OperationalDeductionType::Exempt => 0.0,
        };
    }

    /**
     * The relative pool distributed among relative-type projects is the employee-total budget
     * (resolved via the effective-dated rates table) net of fixed-type projects' own flat charges,
     * which are a carve-out of that same total rather than an addition on top of it.
     */
    private function resolveRelativePool(CarbonInterface|string $asOfDate): float
    {
        $employeeTotal = $this->resolveEffectiveOperationalDeductionAction->execute($asOfDate);
        $fixedSum = $this->sumFixedOperationalDeductionsAction->execute();

        return round(max(0, $employeeTotal - $fixedSum), 2);
    }
}
