<?php

namespace Tests\Feature\AdministrativeFund;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AdministrativeDebtSettlement\Enums\AdministrativeDebtSettlementStatus;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrativeFundApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/administrative-fund';

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

    private function findDay(array $payload, string $date): array
    {
        foreach ($payload['days'] as $row) {
            if ($row['date'] === $date) {
                return $row;
            }
        }

        $this->fail("Day {$date} not found in administrative fund response.");
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

    public function test_empty_month_returns_all_days_with_zeros(): void
    {
        $this->actAs('finance');

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('message', __('messages.administrative_fund_fetched_successfully'))
            ->json('data');

        $this->assertSame(8, $data['month']);
        $this->assertSame(2026, $data['year']);
        $this->assertCount(31, $data['days']);
        $this->assertSame('2026-08-01', $data['days'][0]['date']);
        $this->assertSame('Saturday', $data['days'][0]['day']);
        $this->assertSame('0.00', $data['summary']['project_administration']);
        $this->assertSame('0.00', $data['summary']['cash_fund_contributions']);
        $this->assertSame('0.00', $data['summary']['operational_administration']);
        $this->assertSame('0.00', $data['totals']['net']);
    }

    public function test_project_administration_from_daily_journal(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // collected = fee − debt + contribution = 100 − 40 + 10 = 70
        $this->seedEntry($project->id, '2026-08-10', [
            'administrative_fee' => 100,
            'administrative_debt' => 40,
            'contribution' => 10,
        ]);

        $exempt = $this->createProject(['administrative_exempt' => true]);
        $this->seedEntry($exempt->id, '2026-08-10', [
            'administrative_fee' => 50,
            'administrative_debt' => 0,
            'contribution' => 0,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-10');

        $this->assertSame('70.00', $day['project_administration']);
        $this->assertSame('70.00', $day['total_income']);
        $this->assertSame('70.00', $data['summary']['project_administration']);
        $this->assertSame('70.00', $data['totals']['project_administration']);
    }

    public function test_debt_recovery_from_ads_created_at_day(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $settlement = AdministrativeDebtSettlement::query()->create([
            'year' => 2026,
            'month' => 8,
            'project_id' => $project->id,
            'surplus_at_settlement' => 500,
            'recoverable_amount' => 80,
            'allocated_cash_box' => 0,
            'allocated_current_debt' => 50,
            'allocated_carried_debt' => 30,
            'status' => AdministrativeDebtSettlementStatus::Paid,
            'settled_by' => null,
        ]);
        $settlement->forceFill(['created_at' => Carbon::parse('2026-08-12 15:00:00')])->save();

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $day = $this->findDay($data, '2026-08-12');

        $this->assertSame('80.00', $day['debt_recovery']);
        $this->assertSame('80.00', $day['total_income']);
        $this->assertSame('80.00', $data['summary']['debt_recovery']);
    }

    public function test_put_updates_manuals_and_recalculates(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();
        $this->seedEntry($project->id, '2026-08-05', [
            'administrative_fee' => 100,
            'administrative_debt' => 0,
            'contribution' => 0,
        ]);

        $data = $this->putJson(self::ENDPOINT.'/2026-08-05', [
            'individual_contributions' => 25.5,
            'asset_administration' => 10,
            'notes' => 'test note',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.administrative_fund_updated_successfully'))
            ->json('data');

        $day = $this->findDay($data, '2026-08-05');
        $this->assertSame('100.00', $day['project_administration']);
        $this->assertSame('25.50', $day['individual_contributions']);
        $this->assertSame('10.00', $day['asset_administration']);
        $this->assertSame('125.50', $day['total_income']);
        $this->assertSame('10.00', $day['total_expenses']);
        $this->assertSame('115.50', $day['net']);
        $this->assertSame('test note', $day['notes']);
        $this->assertSame('125.50', $data['summary']['total_income']);
        $this->assertSame('10.00', $data['summary']['total_expenses']);
        $this->assertSame('115.50', $data['summary']['administrative_net']);
    }

    public function test_notes_only_put_recalculates(): void
    {
        $this->actAs('finance');

        $data = $this->putJson(self::ENDPOINT.'/2026-08-01', [
            'notes' => 'notes only',
        ])->assertOk()->json('data');

        $day = $this->findDay($data, '2026-08-01');
        $this->assertSame('notes only', $day['notes']);
        $this->assertSame('0.00', $day['individual_contributions']);
        $this->assertSame('0.00', $day['net']);
    }

    public function test_rejects_calculated_fields(): void
    {
        $this->actAs('finance');

        $this->putJson(self::ENDPOINT.'/2026-08-01', [
            'project_administration' => 99,
            'individual_contributions' => 1,
        ])->assertStatus(422);
    }

    public function test_february_days_length(): void
    {
        $this->actAs('finance');

        $data = $this->getJson(self::ENDPOINT.'?month=2&year=2026')->assertOk()->json('data');
        $this->assertCount(28, $data['days']);

        $leap = $this->getJson(self::ENDPOINT.'?month=2&year=2024')->assertOk()->json('data');
        $this->assertCount(29, $leap['days']);
    }

    public function test_footer_totals_equal_sum_of_days(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();
        $this->seedEntry($project->id, '2026-08-02', [
            'administrative_fee' => 40,
            'administrative_debt' => 0,
            'contribution' => 0,
        ]);
        $this->putJson(self::ENDPOINT.'/2026-08-03', [
            'individual_contributions' => 10,
            'asset_administration' => 5,
        ])->assertOk();

        $data = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');

        $sumIncome = 0.0;
        $sumExpenses = 0.0;
        $sumNet = 0.0;
        foreach ($data['days'] as $day) {
            $sumIncome += (float) $day['total_income'];
            $sumExpenses += (float) $day['total_expenses'];
            $sumNet += (float) $day['net'];
        }

        $this->assertSame($data['totals']['total_income'], number_format($sumIncome, 2, '.', ''));
        $this->assertSame($data['totals']['total_expenses'], number_format($sumExpenses, 2, '.', ''));
        $this->assertSame($data['totals']['net'], number_format($sumNet, 2, '.', ''));
        $this->assertSame($data['summary']['administrative_net'], $data['totals']['net']);
    }
}
