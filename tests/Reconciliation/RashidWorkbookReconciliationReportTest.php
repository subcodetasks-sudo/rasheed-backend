<?php

namespace Tests\Reconciliation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AdministrationRates\Actions\BuildAdministrationRatesAction;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Support\RashidWorkbook\ExpectedDailyJournalCalculator;
use Tests\Support\RashidWorkbook\ExpectedFifoCalculator;
use Tests\Support\RashidWorkbook\WorkbookScenarioBuilder;
use Tests\TestCase;

/**
 * Deliberately outside tests/Unit and tests/Feature (not in phpunit.xml's
 * testsuites) - run explicitly with:
 *
 *     vendor/bin/phpunit tests/Reconciliation
 *
 * Feeds the real April-2026 wk.xlsx dataset through the real
 * DailyJournal/Inventory/AdministrationRates code paths and diffs every
 * result against an independently-computed expected value (see
 * tests/Support/RashidWorkbook), NOT against the workbook's own cells -
 * see the report preamble for why. This is a throwaway/ad hoc report, not
 * meant to stay green in CI - it's fine to edit and rerun repeatedly.
 */
class RashidWorkbookReconciliationReportTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES_DIR = __DIR__.'/../Fixtures/RashidWorkbook';

    public function test_rashid_workbook_reconciliation(): void
    {
        $projectDefs = require self::FIXTURES_DIR.'/projects.php';
        $dailyIncomeExpense = json_decode(
            file_get_contents(self::FIXTURES_DIR.'/daily_income_expense.json'),
            true
        );
        $openingBatches = json_decode(
            file_get_contents(self::FIXTURES_DIR.'/inventory_opening_batches.json'),
            true
        );
        $movements = json_decode(
            file_get_contents(self::FIXTURES_DIR.'/inventory_movements.json'),
            true
        );

        Role::findOrCreate('super-admin', 'web');
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('super-admin');
        Sanctum::actingAs($user);

        $builder = new WorkbookScenarioBuilder;

        $projectsByName = $builder->seedProjects($projectDefs);
        $ownerProject = $builder->seedInventoryOwnerProject();
        $itemsByCode = $builder->seedInventoryItems($openingBatches, $ownerProject);
        $createdMovements = $builder->seedInventoryMovements($movements, $itemsByCode, $projectsByName, $ownerProject);

        $projectDefsByName = [];
        foreach ($projectDefs as $def) {
            $projectDefsByName[$def['name']] = $def;
        }

        $dailyJournalMismatches = [];
        $expectedCalculator = new ExpectedDailyJournalCalculator;

        foreach ($dailyIncomeExpense as $date => $incomeExpenseForDay) {
            $response = $builder->saveDailyJournalDay($this, $date, $incomeExpenseForDay, $projectsByName);
            $response->assertOk();

            $expectedForDay = $expectedCalculator->computeDay($projectDefsByName, $incomeExpenseForDay);

            foreach ($projectsByName as $name => $project) {
                $actual = DailyJournalEntry::query()
                    ->where('project_id', $project->id)
                    ->whereDate('journal_date', $date)
                    ->first();

                $this->compareDailyJournalRow($date, $name, $expectedForDay[$name], $actual, $dailyJournalMismatches);
            }
        }

        $fifoMismatches = [];
        $fifoCalculator = new ExpectedFifoCalculator;
        $codeByItemId = [];
        foreach ($itemsByCode as $code => $item) {
            $codeByItemId[$item->id] = $code;
        }
        foreach ($openingBatches as $row) {
            $fifoCalculator->seedOpeningBatch($row['code'], $row['opening_quantity'], $row['opening_unit_price']);
        }

        foreach ($movements as $index => $row) {
            if ($row['type'] === 'incoming') {
                $fifoCalculator->addIncoming($row['item_code'], $row['quantity'], $row['unit_price']);

                continue;
            }

            $expected = $fifoCalculator->consumeOutgoing($row['item_code'], $row['quantity']);
            $expectedTotalCost = round($expected['total_cost'], 2);

            // $createdMovements is index-aligned with $movements (both incoming
            // and outgoing rows created in exact fixture order), so this is the
            // real InventoryMovement row for this exact outgoing entry.
            $actualMovement = $createdMovements[$index] ?? null;
            $actualTotalCost = $actualMovement ? (float) $actualMovement->total_cost : null;

            if ($actualTotalCost === null || abs($actualTotalCost - $expectedTotalCost) > 0.005) {
                $fifoMismatches[] = [
                    'date' => $row['date'],
                    'item_code' => $row['item_code'],
                    'field' => 'total_cost',
                    'expected' => sprintf('%.2f', $expectedTotalCost),
                    'actual' => $actualMovement ? sprintf('%.2f', $actualTotalCost) : 'MISSING',
                ];
            }
        }

        $expectedRatesTotals = $this->sumExpectedAdministrationRates($projectDefsByName, $dailyIncomeExpense);
        $actualRates = app(BuildAdministrationRatesAction::class)->execute(4, 2026);
        $rateMismatches = $this->compareAdministrationRates($expectedRatesTotals, $actualRates);

        $report = $this->buildReport($dailyJournalMismatches, $fifoMismatches, $rateMismatches);
        $reportPath = sys_get_temp_dir().'/rashid_workbook_reconciliation_report.md';
        file_put_contents($reportPath, $report);

        $this->assertCount(
            0,
            [...$dailyJournalMismatches, ...$fifoMismatches, ...$rateMismatches],
            'Reconciliation mismatches found - see '.$reportPath.' for full detail ('
                .count($dailyJournalMismatches).' DailyJournal, '
                .count($fifoMismatches).' Inventory-FIFO, '
                .count($rateMismatches).' AdministrationRates)'
        );
    }

    private function compareDailyJournalRow(string $date, string $name, array $expected, ?DailyJournalEntry $actual, array &$mismatches): void
    {
        if ($actual === null) {
            $mismatches[] = ['date' => $date, 'project' => $name, 'field' => '(entry)', 'expected' => 'exists', 'actual' => 'MISSING'];

            return;
        }

        $fields = [
            'daily_income', 'daily_expense', 'administrative_fee', 'operational_deduction',
            'administrative_expense', 'daily_total', 'fund_balance', 'administrative_debt',
            'accumulated_administrative_debt',
        ];

        foreach ($fields as $field) {
            $expectedValue = sprintf('%.2f', $expected[$field]);
            $actualValue = sprintf('%.2f', (float) $actual->{$field});

            if ($expectedValue !== $actualValue) {
                $mismatches[] = [
                    'date' => $date,
                    'project' => $name,
                    'field' => $field,
                    'expected' => $expectedValue,
                    'actual' => $actualValue,
                ];
            }
        }
    }

    /**
     * @return array{total_income: float, total_administrative_fee: float}
     */
    private function sumExpectedAdministrationRates(array $projectDefsByName, array $dailyIncomeExpense): array
    {
        $calculator = new ExpectedDailyJournalCalculator;
        $totalIncome = 0.0;
        $totalFee = 0.0;

        foreach ($dailyIncomeExpense as $incomeExpenseForDay) {
            $expectedForDay = $calculator->computeDay($projectDefsByName, $incomeExpenseForDay);

            foreach ($projectDefsByName as $name => $def) {
                if ($def['administrative_exempt']) {
                    continue;
                }

                $totalIncome += $expectedForDay[$name]['daily_income'];
                $totalFee += $expectedForDay[$name]['administrative_fee'];
            }
        }

        return [
            'total_income' => round($totalIncome, 2),
            'total_administrative_fee' => round($totalFee, 2),
        ];
    }

    private function compareAdministrationRates(array $expected, array $actual): array
    {
        $mismatches = [];

        $actualIncome = (float) ($actual['monthly_totals']['month_total_income'] ?? 0);
        $actualFee = (float) ($actual['monthly_totals']['month_total_administrative_percentage'] ?? 0);

        if (sprintf('%.2f', $expected['total_income']) !== sprintf('%.2f', $actualIncome)) {
            $mismatches[] = [
                'field' => 'monthly_totals.month_total_income',
                'expected' => sprintf('%.2f', $expected['total_income']),
                'actual' => sprintf('%.2f', $actualIncome),
            ];
        }

        if (sprintf('%.2f', $expected['total_administrative_fee']) !== sprintf('%.2f', $actualFee)) {
            $mismatches[] = [
                'field' => 'monthly_totals.month_total_administrative_percentage',
                'expected' => sprintf('%.2f', $expected['total_administrative_fee']),
                'actual' => sprintf('%.2f', $actualFee),
            ];
        }

        return $mismatches;
    }

    private function buildReport(array $dailyJournalMismatches, array $fifoMismatches, array $rateMismatches): string
    {
        $lines = [];
        $lines[] = '# Rashid Workbook Reconciliation Report';
        $lines[] = '';
        $lines[] = '## Scope decisions (read before treating any mismatch below as a bug)';
        $lines[] = '';
        $lines[] = '- Expected values are computed with **2-decimal rounding** (current code/EQUATIONS.md), NOT the';
        $lines[] = "  workbook's own whole-currency-unit rounding (`ROUND(x,0)`). Differences from the workbook's own";
        $lines[] = '  cells are expected and are NOT compared here at all - this report only compares the app against';
        $lines[] = '  an independent recomputation of its own documented spec.';
        $lines[] = '- Opening balances are NOT inherited from the workbook (a legacy-model artifact); every project';
        $lines[] = '  starts this run at fund_balance=0, accumulated_administrative_debt=0 (clean cutover).';
        $lines[] = '- The workbook\'s cross-project debt-subsidy mechanic and inventory-expense-as-recoverable-debt';
        $lines[] = '  model are explicitly out of scope (flagged as a follow-up feature gap) and are not seeded/verified.';
        $lines[] = '- administrative_expense is expected to be 0.00 throughout: every outgoing movement in this';
        $lines[] = '  dataset is operational-typed, never administrative-typed.';
        $lines[] = '';
        $lines[] = '## DailyJournal mismatches: '.count($dailyJournalMismatches);
        $lines[] = '';
        if ($dailyJournalMismatches !== []) {
            $lines[] = '| date | project | field | expected | actual |';
            $lines[] = '|---|---|---|---|---|';
            foreach ($dailyJournalMismatches as $m) {
                $lines[] = "| {$m['date']} | {$m['project']} | {$m['field']} | {$m['expected']} | {$m['actual']} |";
            }
        } else {
            $lines[] = '_none_';
        }
        $lines[] = '';
        $lines[] = '## Inventory FIFO mismatches: '.count($fifoMismatches);
        $lines[] = '';
        if ($fifoMismatches !== []) {
            $lines[] = '| date | item_code | field | expected | actual |';
            $lines[] = '|---|---|---|---|---|';
            foreach ($fifoMismatches as $m) {
                $lines[] = "| {$m['date']} | {$m['item_code']} | {$m['field']} | {$m['expected']} | {$m['actual']} |";
            }
        } else {
            $lines[] = '_none_';
        }
        $lines[] = '';
        $lines[] = '## AdministrationRates mismatches: '.count($rateMismatches);
        $lines[] = '';
        if ($rateMismatches !== []) {
            $lines[] = '| field | expected | actual |';
            $lines[] = '|---|---|---|';
            foreach ($rateMismatches as $m) {
                $lines[] = "| {$m['field']} | {$m['expected']} | {$m['actual']} |";
            }
        } else {
            $lines[] = '_none_';
        }
        $lines[] = '';

        return implode("\n", $lines);
    }
}
