<?php

namespace Modules\Notifications\Listeners;

use Modules\AdministrativeFund\Actions\BuildAdministrativeFundAction;
use Modules\AdministrativeFund\Events\AdministrativeFundUpdated;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;

class RefreshAdministrativeFundOnMonthlySummaryUpdate
{
    public function __construct(
        private readonly BuildAdministrativeFundAction $buildAdministrativeFundAction,
    ) {}

    public function handle(MonthlySummaryUpdated $event): void
    {
        $payload = $this->buildAdministrativeFundAction->execute($event->month, $event->year);

        AdministrativeFundUpdated::dispatch($event->year, $event->month, $payload);
    }
}
