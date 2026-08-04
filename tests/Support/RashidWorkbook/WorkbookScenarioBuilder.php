<?php

namespace Tests\Support\RashidWorkbook;

use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\DTOs\CreateInventoryItemData;
use Modules\Inventory\DTOs\IncomingStockData;
use Modules\Inventory\DTOs\OutgoingStockData;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Workflows\CreateIncomingStockWorkflow;
use Modules\Inventory\Workflows\CreateInventoryItemWorkflow;
use Modules\Inventory\Workflows\CreateOutgoingStockWorkflow;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Tests\TestCase;

/**
 * Seeds the real wk.xlsx dataset (see tests/Fixtures/RashidWorkbook) through
 * the app's real Project/Inventory/DailyJournal entry points, shared by the
 * Phase 1 reconciliation report and the Phase 2 permanent regression tests.
 */
class WorkbookScenarioBuilder
{
    /**
     * @param  list<array{name: string, operational_deduction_type: string, operational_fixed_amount: ?float, administrative_exempt: bool, administrative_fee_percentage: float}>  $projectDefs
     * @return array<string, Project>
     */
    public function seedProjects(array $projectDefs): array
    {
        $category = Category::factory()->create(['name' => 'Rashid Workbook Relief Projects']);

        $projectsByName = [];

        foreach ($projectDefs as $def) {
            $projectsByName[$def['name']] = Project::factory()->create([
                'name' => $def['name'],
                'category_id' => $category->id,
                'status' => ProjectStatus::Active,
                'operational_deduction_type' => OperationalDeductionType::from($def['operational_deduction_type']),
                'operational_fixed_amount' => $def['operational_fixed_amount'],
                'administrative_exempt' => $def['administrative_exempt'],
                'administrative_fee_percentage' => $def['administrative_fee_percentage'],
            ]);
        }

        return $projectsByName;
    }

    /**
     * A synthetic, fully-exempt "system" project that owns every catalog item
     * and absorbs the ~9/201 outgoing movements the workbook leaves with no
     * beneficiary. Inert for every calculation under test: it's excluded from
     * the DailyJournal income/expense fixture entirely, and being both
     * operationally-exempt and administrative-exempt keeps it out of the
     * Relative-deduction pool and the AdministrationRates aggregate. Not a
     * 27th real project from the workbook - a seeding necessity because
     * InventoryItem.project_id is a required "owner" column the workbook
     * simply doesn't specify.
     */
    public function seedInventoryOwnerProject(): Project
    {
        $category = Category::factory()->create(['name' => 'Rashid Workbook Inventory System']);

        return Project::factory()->create([
            'name' => 'مخزون تشغيلي (نظام)',
            'category_id' => $category->id,
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
        ]);
    }

    /**
     * @param  list<array{code: string, name: string, unit: string, opening_date: string, opening_quantity: float, opening_unit_price: float}>  $openingBatches
     * @return array<string, InventoryItem>
     */
    public function seedInventoryItems(array $openingBatches, Project $ownerProject): array
    {
        $itemsByCode = [];

        foreach ($openingBatches as $row) {
            Carbon::setTestNow(Carbon::parse($row['opening_date']));

            $inventoryCategory = InventoryCategory::query()->firstOrCreate(
                ['name' => 'raw-materials'],
            );

            $itemsByCode[$row['code']] = app(CreateInventoryItemWorkflow::class)->handle(
                CreateInventoryItemData::fromArray([
                    'name' => $row['name'],
                    'category_id' => $inventoryCategory->id,
                    'project_id' => $ownerProject->id,
                    'unit' => $row['unit'],
                    'opening_price' => $row['opening_unit_price'],
                    'opening_quantity' => $row['opening_quantity'],
                    'minimum_stock_level' => 0,
                    'notes' => null,
                ])
            );
        }

        Carbon::setTestNow();

        return $itemsByCode;
    }

    /**
     * @param  list<array{date: string, item_code: string, type: string, quantity: float, unit_price?: float, beneficiary_project?: ?string, expense_type?: string}>  $movements
     * @param  array<string, InventoryItem>  $itemsByCode
     * @param  array<string, Project>  $projectsByName
     * @return list<InventoryMovement> created movements, index-aligned with $movements
     */
    public function seedInventoryMovements(
        array $movements,
        array $itemsByCode,
        array $projectsByName,
        Project $ownerProject,
    ): array {
        $created = [];

        try {
            foreach ($movements as $row) {
                Carbon::setTestNow(Carbon::parse($row['date']));
                $item = $itemsByCode[$row['item_code']];

                if ($row['type'] === 'incoming') {
                    $created[] = app(CreateIncomingStockWorkflow::class)->handle(IncomingStockData::fromArray([
                        'inventory_item_id' => $item->id,
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'notes' => null,
                    ]));

                    continue;
                }

                $beneficiary = $row['beneficiary_project'] !== null
                    ? $projectsByName[$row['beneficiary_project']]
                    : $ownerProject;

                $created[] = app(CreateOutgoingStockWorkflow::class)->handle(OutgoingStockData::fromArray([
                    'inventory_item_id' => $item->id,
                    'quantity' => $row['quantity'],
                    'beneficiary_project_id' => $beneficiary->id,
                    'expense_type' => $row['expense_type'],
                    'notes' => null,
                ]));
            }
        } finally {
            Carbon::setTestNow();
        }

        return $created;
    }

    /**
     * @param  array<string, array{income: float, expense: float}>  $incomeExpenseForDay
     * @param  array<string, Project>  $projectsByName
     */
    public function saveDailyJournalDay(
        TestCase $test,
        string $date,
        array $incomeExpenseForDay,
        array $projectsByName,
    ): TestResponse {
        $entries = [];

        foreach ($projectsByName as $name => $project) {
            $entries[] = [
                'project_id' => $project->id,
                'daily_income' => $incomeExpenseForDay[$name]['income'],
                'daily_expense' => $incomeExpenseForDay[$name]['expense'],
                'contribution' => 0,
            ];
        }

        return $test->putJson('/api/v1/daily-journals', [
            'journal_date' => $date,
            'entries' => $entries,
        ]);
    }
}
