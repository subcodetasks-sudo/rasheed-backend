<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AdministrationRates\Actions\BuildAdministrationRatesAction;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Inventory\Models\InventoryMovement;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Support\RashidWorkbook\ExpectedDailyJournalCalculator;
use Tests\Support\RashidWorkbook\WorkbookScenarioBuilder;
use Tests\TestCase;

/**
 * Full end-to-end smoke test against the real April-2026 wk.xlsx dataset (see
 * tests/Fixtures/RashidWorkbook and tests/Reconciliation for the exhaustive,
 * one-off comparison this was distilled from). Kept as a single test method
 * so the expensive 30-day/26-project/full-inventory-ledger seed only runs
 * once under RefreshDatabase, asserting a curated checkpoint set rather than
 * every cell.
 */
class RashidWorkbookGoldenPathTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES_DIR = __DIR__.'/../../Fixtures/RashidWorkbook';

    public function test_full_month_matches_independently_computed_expectations(): void
    {
        $projectDefs = require self::FIXTURES_DIR.'/projects.php';
        $dailyIncomeExpense = json_decode(file_get_contents(self::FIXTURES_DIR.'/daily_income_expense.json'), true);
        $openingBatches = json_decode(file_get_contents(self::FIXTURES_DIR.'/inventory_opening_batches.json'), true);
        $movements = json_decode(file_get_contents(self::FIXTURES_DIR.'/inventory_movements.json'), true);

        Role::findOrCreate('super-admin', 'web');
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('super-admin');
        Sanctum::actingAs($user);

        $builder = new WorkbookScenarioBuilder;
        $projectsByName = $builder->seedProjects($projectDefs);
        $ownerProject = $builder->seedInventoryOwnerProject();
        $itemsByCode = $builder->seedInventoryItems($openingBatches, $ownerProject);
        $builder->seedInventoryMovements($movements, $itemsByCode, $projectsByName, $ownerProject);

        $projectDefsByName = [];
        foreach ($projectDefs as $def) {
            $projectDefsByName[$def['name']] = $def;
        }

        $calculator = new ExpectedDailyJournalCalculator;
        $expectedByDate = [];
        $dates = array_keys($dailyIncomeExpense);
        sort($dates);

        foreach ($dates as $date) {
            $builder->saveDailyJournalDay($this, $date, $dailyIncomeExpense[$date], $projectsByName)->assertOk();
            $expectedByDate[$date] = $calculator->computeDay($projectDefsByName, $dailyIncomeExpense[$date]);
        }

        $firstDate = $dates[0];
        $midDate = $dates[14];
        $lastDate = $dates[array_key_last($dates)];

        // One representative Relative project + the 3 special cases.
        $checkpoints = ['تكية اطعام', 'سقيا ماء', 'رغيف خيري', 'توزيع نقدي'];

        foreach ($checkpoints as $name) {
            $project = $projectsByName[$name];

            foreach ([$firstDate, $midDate] as $date) {
                $actual = DailyJournalEntry::query()
                    ->where('project_id', $project->id)
                    ->whereDate('journal_date', $date)
                    ->first();

                $this->assertNotNull($actual, "missing entry for {$name} on {$date}");
                $this->assertSame(
                    sprintf('%.2f', $expectedByDate[$date][$name]['operational_deduction']),
                    sprintf('%.2f', (float) $actual->operational_deduction),
                    "operational_deduction mismatch for {$name} on {$date}"
                );
            }

            $lastEntry = DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $lastDate)
                ->first();

            $this->assertNotNull($lastEntry, "missing entry for {$name} on {$lastDate}");
            $this->assertSame(
                sprintf('%.2f', $expectedByDate[$lastDate][$name]['fund_balance']),
                sprintf('%.2f', (float) $lastEntry->fund_balance),
                "fund_balance mismatch for {$name} on {$lastDate}"
            );
            $this->assertSame(
                sprintf('%.2f', $expectedByDate[$lastDate][$name]['accumulated_administrative_debt']),
                sprintf('%.2f', (float) $lastEntry->accumulated_administrative_debt),
                "accumulated_administrative_debt mismatch for {$name} on {$lastDate}"
            );
        }

        // Full-month AdministrationRates aggregate (collected = fee - debt + contribution).
        $expectedTotalIncome = 0.0;
        $expectedTotalCollected = 0.0;
        foreach ($dates as $date) {
            foreach ($projectDefsByName as $name => $def) {
                if ($def['administrative_exempt']) {
                    continue;
                }
                $row = $expectedByDate[$date][$name];
                $expectedTotalIncome += $row['daily_income'];
                $expectedTotalCollected += $row['administrative_fee']
                    - $row['administrative_debt']
                    + ($row['contribution'] ?? 0);
            }
        }

        $rates = app(BuildAdministrationRatesAction::class)->execute(4, 2026);
        $this->assertSame(sprintf('%.2f', $expectedTotalIncome), $rates['monthly_totals']['month_total_income']);
        $this->assertSame(sprintf('%.2f', $expectedTotalCollected), $rates['monthly_totals']['month_total_administrative_percentage']);

        // Row-count sanity. +1 project accounts for the synthetic inventory-owner
        // project, which EnsureDailyJournalEntriesForActiveProjectsAction also
        // sweeps into a (null-income) entry every day since it's Active too.
        $this->assertSame((count($projectDefs) + 1) * count($dates), DailyJournalEntry::query()->count());
        $incomingCount = count(array_filter($movements, fn ($m) => $m['type'] === 'incoming'));
        $outgoingCount = count(array_filter($movements, fn ($m) => $m['type'] === 'outgoing'));
        $this->assertSame($incomingCount, InventoryMovement::query()->where('type', 'incoming')->count());
        $this->assertSame($outgoingCount, InventoryMovement::query()->where('type', 'outgoing')->count());
    }
}
