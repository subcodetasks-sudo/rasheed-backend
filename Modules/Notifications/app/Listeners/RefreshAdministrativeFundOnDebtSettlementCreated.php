<?php

namespace Modules\Notifications\Listeners;

use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\AdministrativeFund\Actions\BuildAdministrativeFundAction;
use Modules\AdministrativeFund\Events\AdministrativeFundUpdated;

class RefreshAdministrativeFundOnDebtSettlementCreated
{
    public function __construct(
        private readonly BuildAdministrativeFundAction $buildAdministrativeFundAction,
    ) {}

    public function handle(AdministrativeDebtSettlementCreated $event): void
    {
        $year = (int) $event->settlement->year;
        $month = (int) $event->settlement->month;

        $payload = $this->buildAdministrativeFundAction->execute($month, $year);

        AdministrativeFundUpdated::dispatch($year, $month, $payload);
    }
}
