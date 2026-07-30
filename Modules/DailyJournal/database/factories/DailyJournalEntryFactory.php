<?php

namespace Modules\DailyJournal\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Models\Project;

/**
 * @extends Factory<DailyJournalEntry>
 */
class DailyJournalEntryFactory extends Factory
{
    protected $model = DailyJournalEntry::class;

    public function definition(): array
    {
        return [
            'journal_date' => now()->toDateString(),
            'project_id' => Project::factory(),
            'daily_income' => null,
            'daily_expense' => null,
            'contribution' => null,
            'administrative_expense' => 0,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_total' => 0,
            'fund_balance' => 0,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
        ];
    }
}
