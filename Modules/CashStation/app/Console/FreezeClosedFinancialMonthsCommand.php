<?php

namespace Modules\CashStation\Console;

use Illuminate\Console\Command;
use Modules\CashStation\Actions\FreezeFinancialMonthAction;

class FreezeClosedFinancialMonthsCommand extends Command
{
    protected $signature = 'financial-months:freeze-closed';

    protected $description = 'Freeze Cash Station and Monthly Summary snapshots for all closed calendar months';

    public function handle(FreezeFinancialMonthAction $freezeFinancialMonthAction): int
    {
        $locks = $freezeFinancialMonthAction->freezeAllClosed();

        $this->info(sprintf('Frozen %d closed financial month(s).', count($locks)));

        foreach ($locks as $lock) {
            $this->line(sprintf(' - %04d-%02d (frozen_at: %s)', $lock->year, $lock->month, $lock->frozen_at));
        }

        return self::SUCCESS;
    }
}
