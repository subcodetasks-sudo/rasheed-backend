<?php

namespace Modules\AdministrativeDebtSettlement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;

class AdministrativeDebtSettlementCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AdministrativeDebtSettlement $settlement,
    ) {}
}
