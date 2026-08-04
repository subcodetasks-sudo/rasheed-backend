<?php

namespace Modules\CashFundExpenses\Workflows;

use Modules\CashFundExpenses\Actions\BuildCashFundExpensesAction;

class ShowCashFundExpensesWorkflow
{
    public function __construct(
        private readonly BuildCashFundExpensesAction $buildCashFundExpensesAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->buildCashFundExpensesAction->execute($month, $year);
    }
}
