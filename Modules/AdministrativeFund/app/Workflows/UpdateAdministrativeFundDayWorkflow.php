<?php

namespace Modules\AdministrativeFund\Workflows;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AdministrativeFund\Actions\BuildAdministrativeFundAction;
use Modules\AdministrativeFund\Actions\UpsertAdministrativeFundDayAction;
use Modules\AdministrativeFund\Events\AdministrativeFundUpdated;

class UpdateAdministrativeFundDayWorkflow
{
    public function __construct(
        private readonly UpsertAdministrativeFundDayAction $upsertAdministrativeFundDayAction,
        private readonly BuildAdministrativeFundAction $buildAdministrativeFundAction,
    ) {}

    /**
     * @param  array{individual_contributions?: float|null, asset_administration?: float|null, notes?: string|null}  $data
     */
    public function handle(string $date, array $data): array
    {
        $payload = DB::transaction(function () use ($date, $data) {
            $this->upsertAdministrativeFundDayAction->execute(
                $date,
                $data,
                auth()->user()?->uuid,
            );

            $carbon = Carbon::parse($date);

            return $this->buildAdministrativeFundAction->execute(
                (int) $carbon->month,
                (int) $carbon->year,
            );
        });

        $carbon = Carbon::parse($date);
        AdministrativeFundUpdated::dispatch(
            (int) $carbon->year,
            (int) $carbon->month,
            $payload,
        );

        return $payload;
    }
}
