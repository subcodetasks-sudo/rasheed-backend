<?php

namespace Modules\CashStation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\CashStation\Models\CashStationMonthCarry;

class CashStationCarriedForward
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CashStationMonthCarry $carry,
    ) {}
}
