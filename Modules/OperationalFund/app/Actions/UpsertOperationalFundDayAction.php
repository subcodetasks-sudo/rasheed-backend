<?php

namespace Modules\OperationalFund\Actions;

use Modules\OperationalFund\Models\OperationalFundDay;

class UpsertOperationalFundDayAction
{
    /**
     * Replace the day's operational expense line items wholesale and cache their sum
     * onto operational_fund_days.operational_expense (the value other modules read).
     *
     * @param  list<array{amount: float, description: string}>  $expenses
     */
    public function execute(string $date, array $expenses, ?string $updatedBy = null): OperationalFundDay
    {
        $day = OperationalFundDay::query()
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first();

        if ($day === null) {
            $day = new OperationalFundDay(['date' => $date]);
        }

        $day->operational_expense = round(
            array_sum(array_map(static fn (array $e): float => max(0, $e['amount']), $expenses)),
            2,
        );
        $day->updated_by = $updatedBy;
        $day->save();

        $day->items()->delete();

        foreach ($expenses as $expense) {
            $day->items()->create([
                'amount' => round(max(0, $expense['amount']), 2),
                'description' => $expense['description'],
                'updated_by' => $updatedBy,
            ]);
        }

        return $day->refresh();
    }
}
