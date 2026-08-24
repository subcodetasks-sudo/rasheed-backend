<?php

namespace Modules\Notifications\Listeners;

use Modules\OperationalFund\Events\OperationalFundUpdated;
use Modules\OperationalRate\Actions\BuildOperationalRateAction;
use Modules\OperationalRate\Events\OperationalRateUpdated;

class RefreshOperationalRateOnOperationalFundUpdate
{
    public function __construct(
        private readonly BuildOperationalRateAction $buildOperationalRateAction,
    ) {}

    public function handle(OperationalFundUpdated $event): void
    {
        $payload = $this->buildOperationalRateAction->execute($event->month, $event->year);

        OperationalRateUpdated::dispatch($event->year, $event->month, $payload);
    }
}
