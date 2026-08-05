<?php

namespace Modules\OperationalFund\Actions;

use Modules\OperationalFund\Models\OperationalFundDay;

class UpsertOperationalFundDayAction
{
    public function execute(string $date, float $operationalExpense, ?string $updatedBy = null): OperationalFundDay
    {
        $day = OperationalFundDay::query()
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first();

        if ($day === null) {
            $day = new OperationalFundDay(['date' => $date]);
        }

        $day->operational_expense = round(max(0, $operationalExpense), 2);
        $day->updated_by = $updatedBy;
        $day->save();

        return $day;
    }
}
