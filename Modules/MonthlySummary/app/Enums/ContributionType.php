<?php

namespace Modules\MonthlySummary\Enums;

enum ContributionType: string
{
    case FundDeficit = 'fund_deficit';
    case AdministrativeDebt = 'administrative_debt';
}
