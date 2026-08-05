<?php

namespace Modules\Notifications\Listeners;

use Carbon\Carbon;
use Modules\OperationalRate\Actions\BuildOperationalRateAction;
use Modules\OperationalRate\Events\OperationalRateUpdated;
use Modules\Project\Events\ProjectUpdated;

class RefreshOperationalRateOnProjectUpdate
{
    public function __construct(
        private readonly BuildOperationalRateAction $buildOperationalRateAction,
    ) {}

    public function handle(ProjectUpdated $event): void
    {
        $now = Carbon::now();
        $year = (int) $now->year;
        $month = (int) $now->month;

        $payload = $this->buildOperationalRateAction->execute($month, $year);

        OperationalRateUpdated::dispatch($year, $month, $payload);
    }
}
