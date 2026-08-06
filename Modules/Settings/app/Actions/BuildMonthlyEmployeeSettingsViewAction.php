<?php

namespace Modules\Settings\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Modules\Project\Actions\Project\SumFixedOperationalDeductionsAction;
use Modules\Settings\app\Models\MonthlyEmployeeSetting;
use Modules\Settings\Support\MonthlyEmployeeCategories;

class BuildMonthlyEmployeeSettingsViewAction
{
    public function __construct(
        private readonly SumFixedOperationalDeductionsAction $sumFixedOperationalDeductionsAction,
    ) {}

    public function execute(int $month, int $year, ?MonthlyEmployeeSetting $row = null): array
    {
        $row ??= MonthlyEmployeeSetting::query()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $categories = $row !== null
            ? $row->categoryAmounts()
            : MonthlyEmployeeCategories::zeros();

        $relative = MonthlyEmployeeCategories::sum($categories);
        $fixed = $this->sumFixedOperationalDeductionsAction->execute();
        $total = round($relative + $fixed, 2);

        $categoryDecimals = [];
        foreach ($categories as $key => $value) {
            $categoryDecimals[$key] = FormatMoneyDecimal::format($value);
        }

        return [
            'month' => $month,
            'year' => $year,
            'categories' => $categoryDecimals,
            'relative_deduction' => FormatMoneyDecimal::format($relative),
            'fixed_project_deductions' => FormatMoneyDecimal::format($fixed),
            'total_daily_operational_deduction' => FormatMoneyDecimal::format($total),
        ];
    }
}
