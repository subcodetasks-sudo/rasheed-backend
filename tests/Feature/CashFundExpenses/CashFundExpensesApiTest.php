<?php

namespace Tests\Feature\CashFundExpenses;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashFundExpensesApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/cash-fund-expenses';

    private function actAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createProject(array $attrs = []): Project
    {
        return Project::factory()->create(array_merge([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ], $attrs));
    }

    private function seedEntry(int $projectId, string $date, array $attrs = []): DailyJournalEntry
    {
        return DailyJournalEntry::factory()->create(array_merge([
            'project_id' => $projectId,
            'journal_date' => $date,
            'daily_income' => 0,
            'daily_expense' => 0,
            'contribution' => 0,
            'administrative_expense' => 0,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_total' => 0,
            'fund_balance' => 0,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
        ], $attrs));
    }

    private function findProject(array $payload, int $projectId): array
    {
        foreach ($payload['projects'] as $row) {
            if ((int) $row['project_id'] === $projectId) {
                return $row;
            }
        }

        $this->fail("Project {$projectId} not found in cash fund expenses response.");
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertForbidden();
    }

    public function test_empty_month_returns_no_projects(): void
    {
        $this->actAs('finance');

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('message', __('messages.cash_fund_expenses_fetched_successfully'))
            ->json('data');

        $this->assertSame(8, $data['month']);
        $this->assertSame(2026, $data['year']);
        $this->assertSame(range(1, 31), $data['days']);
        $this->assertSame([], $data['projects']);
    }

    public function test_aggregates_daily_and_administrative_expense_same_day(): void
    {
        $this->actAs('finance');
        $project = $this->createProject(['name' => 'مشروع أ']);

        $this->seedEntry($project->id, '2026-08-03', [
            'daily_expense' => 250,
            'administrative_expense' => 500,
            'operational_deduction' => 99,
            'administrative_fee' => 40,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $row = $this->findProject($data, $project->id);

        $this->assertSame('مشروع أ', $row['project_name']);
        $this->assertSame('750.00', $row['daily_expenses']['3']);
        $this->assertNull($row['daily_expenses']['1']);
        $this->assertSame('750.00', $row['monthly_total']);
    }

    public function test_single_day_expense_and_monthly_total_matches_days(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-10', ['daily_expense' => 100]);
        $this->seedEntry($project->id, '2026-08-15', ['administrative_expense' => 50.5]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $row = $this->findProject($data, $project->id);

        $this->assertSame('100.00', $row['daily_expenses']['10']);
        $this->assertSame('50.50', $row['daily_expenses']['15']);
        $this->assertSame('150.50', $row['monthly_total']);

        $sum = 0.0;
        foreach ($row['daily_expenses'] as $value) {
            if ($value !== null) {
                $sum += (float) $value;
            }
        }
        $this->assertSame($row['monthly_total'], number_format($sum, 2, '.', ''));
    }

    public function test_two_projects_same_day_are_independent(): void
    {
        $this->actAs('finance');
        $a = $this->createProject(['name' => 'A']);
        $b = $this->createProject(['name' => 'B']);

        $this->seedEntry($a->id, '2026-08-05', ['daily_expense' => 10]);
        $this->seedEntry($b->id, '2026-08-05', ['daily_expense' => 20]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');

        $this->assertSame('10.00', $this->findProject($data, $a->id)['daily_expenses']['5']);
        $this->assertSame('20.00', $this->findProject($data, $b->id)['daily_expenses']['5']);
    }

    public function test_february_days_length(): void
    {
        $this->actAs('finance');

        $data = $this->getJson(self::ENDPOINT.'?month=2&year=2026')->assertOk()->json('data');
        $this->assertSame(range(1, 28), $data['days']);

        $leap = $this->getJson(self::ENDPOINT.'?month=2&year=2024')->assertOk()->json('data');
        $this->assertSame(range(1, 29), $leap['days']);
    }

    public function test_zero_expense_project_is_omitted(): void
    {
        $this->actAs('finance');
        $withExpense = $this->createProject(['name' => 'Has']);
        $zero = $this->createProject(['name' => 'Zero']);

        $this->seedEntry($withExpense->id, '2026-08-01', ['daily_expense' => 5]);
        $this->seedEntry($zero->id, '2026-08-01', [
            'daily_expense' => 0,
            'administrative_expense' => 0,
            'daily_income' => 1000,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');

        $ids = array_map(fn ($row) => (int) $row['project_id'], $data['projects']);
        $this->assertContains($withExpense->id, $ids);
        $this->assertNotContains($zero->id, $ids);
    }
}
