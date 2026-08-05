<?php

namespace Modules\OperationalFund\Enums;

enum DayStatus: string
{
    case OperationalSurplus = 'operational_surplus';
    case OperationalDeficit = 'operational_deficit';
    case Balanced = 'balanced';
}
