<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Support\RashidWorkbook\WorkbookScenarioBuilder;
use Tests\TestCase;

/**
 * The 3 special-case projects from the real wk.xlsx dataset, on a single
 * real day (2026-04-01):
 * - سقيا ماء: Fixed operational deduction (154, regardless of income), pays admin fee.
 * - رغيف خيري: Exempt from operational deduction only, still pays admin fee.
 * - توزيع نقدي: Exempt from operational deduction AND fully administrative_exempt (0% fee).
 */
class RashidWorkbookSpecialProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES_DIR = __DIR__.'/../../Fixtures/RashidWorkbook';

    public function test_fixed_type_project_deducts_flat_amount_regardless_of_income(): void
    {
        $entry = $this->saveDayOneAndFetch('سقيا ماء');

        $this->assertSame('154.00', sprintf('%.2f', (float) $entry->operational_deduction));
        $this->assertSame('120.00', sprintf('%.2f', (float) $entry->administrative_fee)); // 12% of 1000
    }

    public function test_operationally_exempt_project_still_pays_administrative_fee(): void
    {
        $entry = $this->saveDayOneAndFetch('رغيف خيري');

        $this->assertSame('0.00', sprintf('%.2f', (float) $entry->operational_deduction));
        $this->assertSame('48.00', sprintf('%.2f', (float) $entry->administrative_fee)); // 12% of 400
    }

    public function test_fully_exempt_project_pays_neither_fee_nor_operational_deduction(): void
    {
        $entry = $this->saveDayOneAndFetch('توزيع نقدي');

        $this->assertSame('0.00', sprintf('%.2f', (float) $entry->operational_deduction));
        $this->assertSame('0.00', sprintf('%.2f', (float) $entry->administrative_fee));
    }

    private function saveDayOneAndFetch(string $projectName): DailyJournalEntry
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

        $project = $projectsByName[$projectName];

        return DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', $date)
            ->firstOrFail();
    }
}
