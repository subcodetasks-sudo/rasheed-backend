<?php

namespace Modules\OperationalFund\Actions;

use App\Support\Money\FormatMoneyDecimal;
use Carbon\Carbon;
use Modules\OperationalFund\Enums\DayStatus;

class BuildOperationalFundDayAction
{
    public function __construct(
        private readonly ResolveExpectedOperationalIncomeAction $resolveExpectedOperationalIncomeAction,
        private readonly LoadOperationalExpensesByDateAction $loadOperationalExpensesByDateAction,
    ) {}

    public function execute(string $date): array
    {
        $carbon = Carbon::parse($date)->startOfDay();
        $dateString = $carbon->toDateString();

        $income = $this->resolveExpectedOperationalIncomeAction->execute($dateString);
        $expense = $this->loadOperationalExpensesByDateAction->forDate($dateString);
        $net = round($income - $expense, 2);

        return [
            'date' => $dateString,
            'expected_operational_income' => FormatMoneyDecimal::formatRounded($income),
            'operational_expense' => $this->decimal($expense),
            'daily_operational_net' => FormatMoneyDecimal::formatRounded($net),
            'day_status' => $this->status($net)->value,
        ];
    }

    private function status(float $net): DayStatus
    {
        if ($net > 0) {
            return DayStatus::OperationalSurplus;
        }

        if ($net < 0) {
            return DayStatus::OperationalDeficit;
        }

        return DayStatus::Balanced;
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
