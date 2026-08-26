<?php

namespace Modules\CashStation\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\CashStation\Models\FinancialMonthLock;
use Modules\Project\Exceptions\BusinessException;

class AssertFinancialMonthUnlockedAction
{
    public function execute(int $year, int $month): void
    {
        $locked = FinancialMonthLock::query()
            ->where('year', $year)
            ->where('month', $month)
            ->exists();

        if ($locked) {
            throw new BusinessException(__('messages.financial_month_locked'), 422);
        }
    }

    public function executeForDate(CarbonInterface|string $date): void
    {
        $carbon = $date instanceof CarbonInterface
            ? $date
            : Carbon::parse($date);

        $this->execute((int) $carbon->year, (int) $carbon->month);
    }

    public function isLocked(int $year, int $month): bool
    {
        return FinancialMonthLock::query()
            ->where('year', $year)
            ->where('month', $month)
            ->exists();
    }

    public function isDateLocked(CarbonInterface|string $date): bool
    {
        $carbon = $date instanceof CarbonInterface
            ? $date
            : Carbon::parse($date);

        return $this->isLocked((int) $carbon->year, (int) $carbon->month);
    }
}
