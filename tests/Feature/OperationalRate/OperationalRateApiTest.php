<?php

namespace Tests\Feature\OperationalRate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationalRateApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/operational-rate';

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

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertForbidden();
    }

    public function test_finance_gets_200(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_super_admin_gets_200(): void
    {
        $this->actAs('super-admin');
        $this->getJson(self::ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_month_and_year_are_required(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT)->assertStatus(422)
            ->assertJsonValidationErrors(['month', 'year']);
    }

    public function test_empty_month_returns_zeros_and_full_calendar(): void
    {
        $this->actAs('finance');

        $response = $this->getJson(self::ENDPOINT.'?month=8&year=2026');
        $response->assertOk()
            ->assertJsonCount(31, 'data.daily_records')
            ->assertJsonPath('data.summary.relative_operational_deduction', '0.00')
            ->assertJsonPath('data.summary.fixed_operational_deduction', '0.00')
            ->assertJsonPath('data.summary.total_operational_deduction', '0.00')
            ->assertJsonPath('data.monthly_totals.total_income', '0.00')
            ->assertJsonPath('data.monthly_totals.total_operational_deduction', '0.00')
            ->assertJsonPath('data.monthly_totals.total_administrative_percentage', '0.00');

        $day1 = $response->json('data.daily_records.0');
        $this->assertSame('2026-08-01', $day1['date']);
        $this->assertArrayHasKey('day_name', $day1);
        $this->assertSame('0.00', $day1['total_income']);
        $this->assertSame('0.00', $day1['operational_deduction']);
        $this->assertSame('0.00', $day1['administrative_percentage']);
    }

    public function test_relative_only_projects(): void
    {
        $this->actAs('finance');

        $relative = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);

        $this->seedEntry($relative->id, '2026-08-05', [
            'daily_income' => 1000,
            'operational_deduction' => 540.50,
            'administrative_fee' => 120,
            'administrative_debt' => 20,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=8&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.summary.relative_operational_deduction', '540.50')
            ->assertJsonPath('data.summary.fixed_operational_deduction', '0.00')
            ->assertJsonPath('data.summary.total_operational_deduction', '540.50');

        $day = collect($response->json('data.daily_records'))->firstWhere('date', '2026-08-05');
        $this->assertSame('1000.00', $day['total_income']);
        $this->assertSame('540.50', $day['operational_deduction']);
        $this->assertSame('100.00', $day['administrative_percentage']);
    }

    public function test_fixed_only_projects(): void
    {
        $this->actAs('finance');

        $fixed = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => 200,
        ]);

        $this->seedEntry($fixed->id, '2026-08-05', [
            'daily_income' => 0,
            'operational_deduction' => 200,
            'administrative_fee' => 0,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=8&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.summary.relative_operational_deduction', '0.00')
            ->assertJsonPath('data.summary.fixed_operational_deduction', '200.00')
            ->assertJsonPath('data.summary.total_operational_deduction', '200.00');

        $day = collect($response->json('data.daily_records'))->firstWhere('date', '2026-08-05');
        $this->assertSame('0.00', $day['total_income']);
        $this->assertSame('200.00', $day['operational_deduction']);
    }

    public function test_mixed_deduction_types(): void
    {
        $this->actAs('finance');

        $relative = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);
        $fixed = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => 150,
        ]);

        $this->seedEntry($relative->id, '2026-08-10', [
            'daily_income' => 800,
            'operational_deduction' => 300,
            'administrative_fee' => 96,
        ]);
        $this->seedEntry($fixed->id, '2026-08-10', [
            'daily_income' => 200,
            'operational_deduction' => 150,
            'administrative_fee' => 24,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=8&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.summary.relative_operational_deduction', '300.00')
            ->assertJsonPath('data.summary.fixed_operational_deduction', '150.00')
            ->assertJsonPath('data.summary.total_operational_deduction', '450.00')
            ->assertJsonPath('data.monthly_totals.total_income', '1000.00')
            ->assertJsonPath('data.monthly_totals.total_operational_deduction', '450.00')
            ->assertJsonPath('data.monthly_totals.total_administrative_percentage', '120.00');

        $day = collect($response->json('data.daily_records'))->firstWhere('date', '2026-08-10');
        $this->assertSame('1000.00', $day['total_income']);
        $this->assertSame('450.00', $day['operational_deduction']);
        $this->assertSame('120.00', $day['administrative_percentage']);
    }

    public function test_exempt_operational_projects_do_not_contribute_to_deduction(): void
    {
        $this->actAs('finance');

        $exempt = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);
        $relative = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);

        $this->seedEntry($exempt->id, '2026-08-12', [
            'daily_income' => 500,
            'operational_deduction' => 0,
            'administrative_fee' => 60,
        ]);
        $this->seedEntry($relative->id, '2026-08-12', [
            'daily_income' => 500,
            'operational_deduction' => 400,
            'administrative_fee' => 60,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=8&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.summary.relative_operational_deduction', '400.00')
            ->assertJsonPath('data.summary.fixed_operational_deduction', '0.00')
            ->assertJsonPath('data.summary.total_operational_deduction', '400.00')
            ->assertJsonPath('data.monthly_totals.total_income', '1000.00');
    }

    public function test_admin_exempt_excluded_from_administrative_percentage_but_income_included(): void
    {
        $this->actAs('finance');

        $eligible = $this->createProject([
            'administrative_exempt' => false,
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);
        $adminExempt = $this->createProject([
            'administrative_exempt' => true,
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => 50,
        ]);

        $this->seedEntry($eligible->id, '2026-08-15', [
            'daily_income' => 1000,
            'operational_deduction' => 200,
            'administrative_fee' => 120,
            'administrative_debt' => 20,
            'contribution' => 10,
        ]);
        $this->seedEntry($adminExempt->id, '2026-08-15', [
            'daily_income' => 500,
            'operational_deduction' => 50,
            'administrative_fee' => 0,
            'administrative_debt' => 99,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=8&year=2026');
        $response->assertOk();

        $day = collect($response->json('data.daily_records'))->firstWhere('date', '2026-08-15');
        $this->assertSame('1500.00', $day['total_income']);
        $this->assertSame('250.00', $day['operational_deduction']);
        // collected = 120 - 20 + 10 = 110 (admin-exempt ignored)
        $this->assertSame('110.00', $day['administrative_percentage']);

        $this->assertSame('200.00', $response->json('data.summary.relative_operational_deduction'));
        $this->assertSame('50.00', $response->json('data.summary.fixed_operational_deduction'));
    }

    public function test_month_isolation(): void
    {
        $this->actAs('finance');
        $project = $this->createProject([
            'operational_deduction_type' => OperationalDeductionType::Relative,
        ]);

        $this->seedEntry($project->id, '2026-07-20', [
            'daily_income' => 999,
            'operational_deduction' => 111,
            'administrative_fee' => 50,
        ]);
        $this->seedEntry($project->id, '2026-08-20', [
            'daily_income' => 500,
            'operational_deduction' => 200,
            'administrative_fee' => 60,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=8&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.monthly_totals.total_income', '500.00')
            ->assertJsonPath('data.monthly_totals.total_operational_deduction', '200.00')
            ->assertJsonPath('data.monthly_totals.total_administrative_percentage', '60.00')
            ->assertJsonPath('data.summary.total_operational_deduction', '200.00');
    }

    public function test_response_shape(): void
    {
        $this->actAs('finance');

        $this->getJson(self::ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('data.month.month', 8)
            ->assertJsonPath('data.month.year', 2026)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'relative_operational_deduction',
                        'fixed_operational_deduction',
                        'total_operational_deduction',
                    ],
                    'month' => ['month', 'year'],
                    'daily_records',
                    'monthly_totals' => [
                        'total_income',
                        'total_operational_deduction',
                        'total_administrative_percentage',
                    ],
                ],
            ]);
    }
}
