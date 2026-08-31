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
        private readonly LoadOperationalExpenseItemsByDateAction $loadOperationalExpenseItemsByDateAction,
    ) {}

    public function execute(string $date): array
    {
        $carbon = Carbon::parse($date)->startOfDay();
        $dateString = $carbon->toDateString();

        $income = $this->resolveExpectedOperationalIncomeAction->execute($dateString);
        $expense = $this->loadOperationalExpensesByDateAction->forDate($dateString);
        $items = $this->loadOperationalExpenseItemsByDateAction->forDate($dateString);
        $net = round($income - $expense, 2);

        return [
            'date' => $dateString,
            'expected_operational_income' => FormatMoneyDecimal::formatRounded($income),
            'operational_expense' => FormatMoneyDecimal::formatRounded($expense),
            'daily_operational_net' => FormatMoneyDecimal::formatRounded($net),
            'day_status' => $this->status($net)->value,
            'expenses' => $this->formatItems($items),
        ];
    }

    /**
     * @param  list<array{amount: float, description: string}>  $items
     * @return list<array{amount: string, description: string}>
     */
    private function formatItems(array $items): array
    {
        return array_map(fn (array $item): array => [
            'amount' => FormatMoneyDecimal::formatRounded($item['amount']),
            'description' => $item['description'],
        ], $items);
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
}
