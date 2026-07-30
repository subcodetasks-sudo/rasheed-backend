<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/dashboard';

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
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_finance_gets_200(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_super_admin_gets_200(): void
    {
        $this->actAs('super-admin');
        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_invalid_journal_date_rejected(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?journal_date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['journal_date']);
    }

    public function test_omitted_date_defaults_to_today(): void
    {
        $this->actAs('finance');
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $project = $this->createProject();
        $this->seedEntry($project->id, $today, [
            'daily_income' => 100,
            'daily_expense' => 20,
            'administrative_fee' => 12,
            'operational_deduction' => 5,
        ]);
        $this->seedEntry($project->id, $yesterday, [
            'daily_income' => 999,
            'daily_expense' => 999,
            'administrative_fee' => 999,
            'operational_deduction' => 999,
        ]);

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame('100.00', $response->json('data.total_daily_income'));
        $this->assertSame('20.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('12.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('5.00', $response->json('data.total_operational_deduction'));
    }

    public function test_journal_date_filter_selects_requested_date(): void
    {
        $this->actAs('finance');
        $today = now()->toDateString();
        $target = now()->subDays(3)->toDateString();

        $project = $this->createProject();
        $this->seedEntry($project->id, $today, [
            'daily_income' => 100,
            'daily_expense' => 10,
            'administrative_fee' => 12,
            'operational_deduction' => 5,
        ]);
        $this->seedEntry($project->id, $target, [
            'daily_income' => 250,
            'daily_expense' => 40,
            'administrative_fee' => 30,
            'operational_deduction' => 15,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$target)->assertOk();

        $this->assertSame('250.00', $response->json('data.total_daily_income'));
        $this->assertSame('40.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('30.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('15.00', $response->json('data.total_operational_deduction'));
    }

    public function test_income_and_expenses_include_exempt_and_non_exempt_projects(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();

        $nonExempt = $this->createProject(['administrative_exempt' => false]);
        $exempt = $this->createProject(['administrative_exempt' => true]);

        $this->seedEntry($nonExempt->id, $date, [
            'daily_income' => 100,
            'daily_expense' => 20,
            'administrative_fee' => 12,
            'operational_deduction' => 10,
        ]);
        $this->seedEntry($exempt->id, $date, [
            'daily_income' => 50,
            'daily_expense' => 5,
            'administrative_fee' => 0,
            'operational_deduction' => 7,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        $this->assertSame('150.00', $response->json('data.total_daily_income'));
        $this->assertSame('25.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('17.00', $response->json('data.total_operational_deduction'));
    }

    public function test_administrative_percentage_sums_only_non_exempt_persisted_fees(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();

        $nonExempt = $this->createProject(['administrative_exempt' => false]);
        $exempt = $this->createProject(['administrative_exempt' => true]);

        $this->seedEntry($nonExempt->id, $date, [
            'daily_income' => 1000,
            'administrative_fee' => 120,
        ]);
        $this->seedEntry($exempt->id, $date, [
            'daily_income' => 500,
            'administrative_fee' => 999,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        $this->assertSame('120.00', $response->json('data.total_administrative_percentage'));
    }

    public function test_nulls_and_no_rows_return_zero_decimals(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        $this->assertSame('0.00', $response->json('data.total_daily_income'));
        $this->assertSame('0.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('0.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('0.00', $response->json('data.total_operational_deduction'));

        $project = $this->createProject();
        $this->seedEntry($project->id, $date, [
            'daily_income' => null,
            'daily_expense' => null,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();

        $this->assertSame('0.00', $response->json('data.total_daily_income'));
        $this->assertSame('0.00', $response->json('data.total_daily_expenses'));
        $this->assertSame('0.00', $response->json('data.total_administrative_percentage'));
        $this->assertSame('0.00', $response->json('data.total_operational_deduction'));
    }

    public function test_changing_persisted_rows_is_reflected_on_next_get(): void
    {
        $this->actAs('finance');
        $date = now()->toDateString();
        $project = $this->createProject();

        $entry = $this->seedEntry($project->id, $date, [
            'daily_income' => 100,
            'daily_expense' => 10,
            'administrative_fee' => 12,
            'operational_deduction' => 5,
        ]);

        $first = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();
        $this->assertSame('100.00', $first->json('data.total_daily_income'));
        $this->assertSame('10.00', $first->json('data.total_daily_expenses'));
        $this->assertSame('12.00', $first->json('data.total_administrative_percentage'));
        $this->assertSame('5.00', $first->json('data.total_operational_deduction'));

        $entry->update([
            'daily_income' => 200,
            'daily_expense' => 25,
            'administrative_fee' => 24,
            'operational_deduction' => 8,
        ]);

        $second = $this->getJson(self::ENDPOINT.'?journal_date='.$date)->assertOk();
        $this->assertSame('200.00', $second->json('data.total_daily_income'));
        $this->assertSame('25.00', $second->json('data.total_daily_expenses'));
        $this->assertSame('24.00', $second->json('data.total_administrative_percentage'));
        $this->assertSame('8.00', $second->json('data.total_operational_deduction'));
    }

    public function test_response_contains_only_the_four_specified_fields(): void
    {
        $this->actAs('finance');

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $data = $response->json('data');
        $this->assertSame(
            [
                'total_daily_income',
                'total_daily_expenses',
                'total_administrative_percentage',
                'total_operational_deduction',
            ],
            array_keys($data)
        );
        $this->assertTrue($response->json('success'));
        $this->assertIsString($response->json('message'));
    }
}
