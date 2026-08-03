<?php

namespace Modules\CashStation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\CashStation\Models\CashStationSettlement;

class CashStationSettlementCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CashStationSettlement $settlement,
    ) {}
}
