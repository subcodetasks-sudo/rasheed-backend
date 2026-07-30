<?php

namespace Modules\Project\Enums;

enum OperationalDeductionType: string
{
    case Relative = 'relative';
    case Fixed = 'fixed';
    case Exempt = 'exempt';
}
