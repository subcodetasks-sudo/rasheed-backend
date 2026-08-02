<?php

namespace Tests\Feature\AdministrationRates;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrationRatesApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/administration-rates';

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // auth
    // ---------------------------------------------------------------

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertUnauthorized();
    }

    public function test_inventory_gets_403(): void
    {
        $this->actAs('inventory');
        $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertForbidden();
    }

    public function test_finance_gets_200(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?month=7&year=2026')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_super_admin_gets_200(): void
    {
        $this->actAs('super-admin');
        $this->getJson(self::ENDPOINT.'?month=7&year=2026')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ---------------------------------------------------------------
    // validation
    // ---------------------------------------------------------------

    public function test_month_and_year_are_required(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT)->assertStatus(422)
            ->assertJsonValidationErrors(['month', 'year']);
    }

    public function test_invalid_month_rejected(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?month=13&year=2026')->assertStatus(422)
            ->assertJsonValidationErrors(['month']);
    }

    public function test_invalid_year_rejected(): void
    {
        $this->actAs('finance');
        $this->getJson(self::ENDPOINT.'?month=1&year=1999')->assertStatus(422)
            ->assertJsonValidationErrors(['year']);
    }

    // ---------------------------------------------------------------
    // exempt exclusion
    // ---------------------------------------------------------------

    public function test_exempt_project_excluded_from_all_totals(): void
    {
        $this->actAs('finance');

        $eligible = $this->createProject(['administrative_exempt' => false]);
        $exempt = $this->createProject(['administrative_exempt' => true]);

        $this->seedEntry($eligible->id, '2026-07-15', [
            'daily_income' => 1000,
            'administrative_fee' => 120,
            'administrative_debt' => 50,
        ]);

        $this->seedEntry($exempt->id, '2026-07-15', [
            'daily_income' => 500,
            'administrative_fee' => 0,
            'administrative_debt' => 30,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026');
        $response->assertOk();

        // Summary: only eligible project (collected = fee - debt + contribution)
        $this->assertSame('1000.00', $response->json('data.summary.total_institution_income'));
        $this->assertSame('70.00', $response->json('data.summary.total_administrative_percentage'));
        $this->assertSame('50.00', $response->json('data.summary.total_administrative_debt'));

        // Monthly totals: same
        $this->assertSame('1000.00', $response->json('data.monthly_totals.month_total_income'));
        $this->assertSame('70.00', $response->json('data.monthly_totals.month_total_administrative_percentage'));
        $this->assertSame('50.00', $response->json('data.monthly_totals.month_total_administrative_debt'));

        // Day 15 should have eligible data only
        $day15 = collect($response->json('data.daily_records'))->firstWhere('date', '2026-07-15');
        $this->assertSame('1000.00', $day15['total_income']);
        $this->assertSame('70.00', $day15['administrative_percentage']);
        $this->assertSame('50.00', $day15['administrative_debt']);
    }

    public function test_contribution_inside_debt_does_not_reduce_collected_percentage(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // fee 120, debt 80 (= 50 fee-consumption + 30 contribution) → collected 70
        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 1000,
            'contribution' => 30,
            'administrative_fee' => 120,
            'administrative_debt' => 80,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026');
        $response->assertOk();

        $this->assertSame('70.00', $response->json('data.summary.total_administrative_percentage'));
        $this->assertSame('70.00', $response->json('data.monthly_totals.month_total_administrative_percentage'));

        $day15 = collect($response->json('data.daily_records'))->firstWhere('date', '2026-07-15');
        $this->assertSame('70.00', $day15['administrative_percentage']);
        $this->assertSame('80.00', $day15['administrative_debt']);
    }

    // ---------------------------------------------------------------
    // calendar shape
    // ---------------------------------------------------------------

    public function test_daily_records_cover_every_day_of_month(): void
    {
        $this->actAs('finance');
        $this->createProject();

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026');
        $response->assertOk()
            ->assertJsonCount(31, 'data.daily_records');

        $this->assertSame('2026-07-01', $response->json('data.daily_records.0.date'));
        $this->assertSame('2026-07-31', $response->json('data.daily_records.30.date'));

        // February 2026
        $feb = $this->getJson(self::ENDPOINT.'?month=2&year=2026');
        $feb->assertOk()->assertJsonCount(28, 'data.daily_records');
    }

    public function test_days_without_data_show_zeros(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 500,
            'administrative_fee' => 60,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026');

        $day1 = collect($response->json('data.daily_records'))->firstWhere('date', '2026-07-01');
        $this->assertSame('0.00', $day1['total_income']);
        $this->assertSame('0.00', $day1['administrative_percentage']);
        $this->assertSame('0.00', $day1['administrative_debt']);
    }

    // ---------------------------------------------------------------
    // summary is all-time
    // ---------------------------------------------------------------

    public function test_summary_is_all_time_not_month_scoped(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-06-15', [
            'daily_income' => 2000,
            'administrative_fee' => 240,
            'administrative_debt' => 100,
        ]);

        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 500,
            'administrative_fee' => 60,
            'administrative_debt' => 20,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026');
        $response->assertOk();

        // All-time collected: (240-100) + (60-20) = 180
        $this->assertSame('2500.00', $response->json('data.summary.total_institution_income'));
        $this->assertSame('180.00', $response->json('data.summary.total_administrative_percentage'));
        $this->assertSame('120.00', $response->json('data.summary.total_administrative_debt'));

        // Monthly: only July collected = 60 - 20
        $this->assertSame('500.00', $response->json('data.monthly_totals.month_total_income'));
        $this->assertSame('40.00', $response->json('data.monthly_totals.month_total_administrative_percentage'));
        $this->assertSame('20.00', $response->json('data.monthly_totals.month_total_administrative_debt'));
    }

    // ---------------------------------------------------------------
    // read-through after journal save
    // ---------------------------------------------------------------

    public function test_rates_reflect_journal_save_without_recalculation(): void
    {
        $this->actAs('super-admin');
        $project = $this->createProject([
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-20',
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 1000],
            ],
        ])->assertOk();

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026');
        $response->assertOk();

        $day20 = collect($response->json('data.daily_records'))->firstWhere('date', '2026-07-20');
        $this->assertSame('1000.00', $day20['total_income']);
        $this->assertSame('120.00', $day20['administrative_percentage']);

        $this->assertSame('1000.00', $response->json('data.monthly_totals.month_total_income'));
        $this->assertSame('120.00', $response->json('data.monthly_totals.month_total_administrative_percentage'));
    }

    // ---------------------------------------------------------------
    // response shape
    // ---------------------------------------------------------------

    public function test_response_contains_percentage_and_month(): void
    {
        $this->actAs('finance');

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026');
        $response->assertOk()
            ->assertJsonPath('data.administrative_percentage', 12)
            ->assertJsonPath('data.month.month', 7)
            ->assertJsonPath('data.month.year', 2026)
            ->assertJsonStructure([
                'data' => [
                    'administrative_percentage',
                    'summary' => ['total_institution_income', 'total_administrative_percentage', 'total_administrative_debt'],
                    'month' => ['month', 'year'],
                    'daily_records',
                    'monthly_totals' => ['month_total_income', 'month_total_administrative_percentage', 'month_total_administrative_debt'],
                ],
            ]);
    }
}
