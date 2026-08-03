<?php

namespace Modules\AdministrativeDebtSettlement\Enums;

enum AdministrativeDebtSettlementStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
}
