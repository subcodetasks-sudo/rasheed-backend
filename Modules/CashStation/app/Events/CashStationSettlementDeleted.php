<?php

namespace Modules\CashStation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashStationSettlementDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $settlementId,
        public readonly int $year,
        public readonly int $month,
    ) {}
}
