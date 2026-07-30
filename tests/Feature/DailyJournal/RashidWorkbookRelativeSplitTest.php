<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Support\RashidWorkbook\ExpectedDailyJournalCalculator;
use Tests\Support\RashidWorkbook\WorkbookScenarioBuilder;
use Tests\TestCase;

/**
 * The 23 Relative-type projects from the real wk.xlsx dataset, exercised on
 * a single real day (2026-04-01), asserting the relative-split operational
 * deduction math against an independent recomputation. Deliberately does not
 * seed the full 30-day grid - see RashidWorkbookGoldenPathTest for the
 * full-month smoke test and tests/Reconciliation for the exhaustive report.
 */
class RashidWorkbookRelativeSplitTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES_DIR = __DIR__.'/../../Fixtures/RashidWorkbook';

    public function test_relative_operational_deduction_split_matches_real_day_one_income(): void
    {
        $projectDefs = require self::FIXTURES_DIR.'/projects.php';
        $dailyIncomeExpense = json_decode(file_get_contents(self::FIXTURES_DIR.'/daily_income_expense.json'), true);
        $date = '2026-04-01';
        $incomeExpenseForDay = $dailyIncomeExpense[$date];

        Role::findOrCreate('finance', 'web');
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('finance');
        Sanctum::actingAs($user);

        $projectsByName = (new WorkbookScenarioBuilder)->seedProjects($projectDefs);

        $projectDefsByName = [];
        $relativeNames = [];
        foreach ($projectDefs as $def) {
            $projectDefsByName[$def['name']] = $def;
            if ($def['operational_deduction_type'] === 'relative') {
                $relativeNames[] = $def['name'];
            }
        }
        $this->assertCount(23, $relativeNames, 'expected exactly 23 Relative-type projects in the fixture');

        $expected = (new ExpectedDailyJournalCalculator)->computeDay($projectDefsByName, $incomeExpenseForDay);

        $entries = [];
        foreach ($projectsByName as $name => $project) {
            $entries[] = [
                'project_id' => $project->id,
                'daily_income' => $incomeExpenseForDay[$name]['income'],
                'daily_expense' => $incomeExpenseForDay[$name]['expense'],
                'contribution' => 0,
            ];
        }

        $this->putJson('/api/v1/daily-journals', ['journal_date' => $date, 'entries' => $entries])->assertOk();

        $sumOfActualDeductions = 0.0;
        $sumOfExpectedDeductions = 0.0;

        foreach ($relativeNames as $name) {
            $project = $projectsByName[$name];
            $actual = DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $date)
                ->value('operational_deduction');

            $this->assertSame(
                sprintf('%.2f', $expected[$name]['operational_deduction']),
                sprintf('%.2f', (float) $actual),
                "operational_deduction mismatch for {$name}"
            );

            $sumOfActualDeductions += (float) $actual;
            $sumOfExpectedDeductions += $expected[$name]['operational_deduction'];
        }

        // Individually-rounded shares don't sum to exactly 1081 - assert the
        // real system's rounding remainder matches the independently
        // computed one, rather than guessing a tolerance band.
        $this->assertSame(sprintf('%.2f', $sumOfExpectedDeductions), sprintf('%.2f', $sumOfActualDeductions));
    }
}
