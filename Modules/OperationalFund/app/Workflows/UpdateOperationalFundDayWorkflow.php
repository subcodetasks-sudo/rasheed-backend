<?php

namespace Modules\OperationalFund\Workflows;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\OperationalFund\Actions\BuildOperationalFundMonthAction;
use Modules\OperationalFund\Actions\UpsertOperationalFundDayAction;
use Modules\OperationalFund\Events\OperationalFundUpdated;

class UpdateOperationalFundDayWorkflow
{
    public function __construct(
        private readonly UpsertOperationalFundDayAction $upsertOperationalFundDayAction,
        private readonly BuildOperationalFundMonthAction $buildOperationalFundMonthAction,
    ) {}

    public function handle(string $date, float $operationalExpense): array
    {
        $payload = DB::transaction(function () use ($date, $operationalExpense) {
            $this->upsertOperationalFundDayAction->execute(
                $date,
                $operationalExpense,
                auth()->user()?->uuid,
            );

            $carbon = Carbon::parse($date);

            return $this->buildOperationalFundMonthAction->execute(
                (int) $carbon->month,
                (int) $carbon->year,
            );
        });

        $carbon = Carbon::parse($date);
        OperationalFundUpdated::dispatch(
            (int) $carbon->year,
            (int) $carbon->month,
            $payload,
        );

        return $payload;
    }
}
