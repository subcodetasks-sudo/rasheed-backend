<?php

namespace Modules\DailyJournal\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\DailyJournal\Models\DailyJournalEntry;

class AdministrativeDebtRepaid
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly DailyJournalEntry $entry,
    ) {}
}
