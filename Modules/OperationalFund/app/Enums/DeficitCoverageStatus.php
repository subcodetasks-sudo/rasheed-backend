<?php

namespace Modules\OperationalFund\Enums;

enum DeficitCoverageStatus: string
{
    case Covered = 'covered';
    case NotCovered = 'not_covered';
}
