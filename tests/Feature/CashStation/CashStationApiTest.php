<?php

namespace Tests\Feature\CashStation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashStationApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/cash-station';

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

        $this->fail("Project {$projectId} not found in cash station response.");
    }

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
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('messages.cash_station_fetched_successfully'));
    }

    public function test_super_admin_gets_200(): void
    {
        $this->actAs('super-admin');
        $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk();
    }

    public function test_monthly_total_uses_underlying_fields_not_daily_total_or_fund_balance(): void
    {
        $this->actAs('finance');
        $project = $this->createProject(['name' => 'مشروع أ']);

        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 1000,
            'administrative_fee' => 100,
            'operational_deduction' => 50,
            'daily_expense' => 200,
            'daily_total' => 9999,
            'fund_balance' => 8888,
            'accumulated_administrative_debt' => 25,
        ]);

        $this->seedEntry($project->id, '2026-07-20', [
            'daily_income' => 500,
            'administrative_fee' => 40,
            'operational_deduction' => 10,
            'daily_expense' => 100,
            'daily_total' => 7777,
            'fund_balance' => 6666,
            'accumulated_administrative_debt' => 40,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk();
        $data = $response->json('data');
        $row = $this->findProject($data, $project->id);

        // (1000+500) - (100+40) - (50+10) - (200+100) = 1000
        $this->assertSame('1000.00', $row['monthly_total']);
        $this->assertSame('0.00', $row['previous_monthly_total']);
        $this->assertSame('1000.00', $row['net_cash_fund']);
        $this->assertSame('40.00', $row['administrative_debt']);
        $this->assertSame('40.00', $row['remaining_administrative_debt']);
        $this->assertSame('surplus', $row['status']);

        $this->assertSame('1500.00', $data['summary']['monthly_revenue']);
        $this->assertSame('300.00', $data['summary']['monthly_expenses']);
        $this->assertSame('140.00', $data['summary']['total_administrative_percentage']);
        $this->assertSame('60.00', $data['summary']['total_operational_deduction']);
        $this->assertSame('1000.00', $data['summary']['net_month']);
        $this->assertSame('1000.00', $data['summary']['net_cash_funds']);
        $this->assertSame('40.00', $data['summary']['administrative_debts']);
        $this->assertFalse($data['carried_forward_from_previous']);
    }

    public function test_carry_forward_sets_previous_from_monthly_total_not_net_cash_fund(): void
    {
        $this->actAs('finance');
        $surplus = $this->createProject(['name' => 'فائض']);
        $deficit = $this->createProject(['name' => 'عجز']);

        $this->seedEntry($surplus->id, '2026-07-15', [
            'daily_income' => 2000,
            'administrative_fee' => 100,
            'operational_deduction' => 0,
            'daily_expense' => 400,
        ]);
        // July monthly total = 1500

        $this->seedEntry($deficit->id, '2026-07-15', [
            'daily_income' => 100,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 600,
        ]);
        // July monthly total = -500

        $july = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $this->assertSame('0.00', $this->findProject($july, $surplus->id)['previous_monthly_total']);
        $this->assertFalse($july['carried_forward_from_previous']);

        $this->postJson(self::ENDPOINT.'/carry-forward', [
            'month' => 7,
            'year' => 2026,
        ])->assertOk()
            ->assertJsonPath('message', __('messages.cash_station_carried_forward_successfully'));

        $this->seedEntry($surplus->id, '2026-08-05', [
            'daily_income' => 300,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 50,
        ]);
        // August monthly total = 250

        $august = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $this->assertTrue($august['carried_forward_from_previous']);

        $surplusRow = $this->findProject($august, $surplus->id);
        $this->assertSame('1500.00', $surplusRow['previous_monthly_total']);
        $this->assertSame('250.00', $surplusRow['monthly_total']);
        $this->assertSame('1750.00', $surplusRow['net_cash_fund']);

        $deficitRow = $this->findProject($august, $deficit->id);
        $this->assertSame('-500.00', $deficitRow['previous_monthly_total']);
        $this->assertSame('0.00', $deficitRow['monthly_total']);
        $this->assertSame('-500.00', $deficitRow['net_cash_fund']);
    }

    public function test_previous_remains_zero_until_carry_forward_is_executed(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 1000,
            'daily_expense' => 200,
        ]);

        $august = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $this->assertFalse($august['carried_forward_from_previous']);
        $this->assertSame('0.00', $this->findProject($august, $project->id)['previous_monthly_total']);
    }

    public function test_editing_carried_month_updates_dependent_previous_monthly_total(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $entry = $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 1000,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 200,
        ]);
        // monthly total = 800

        $this->postJson(self::ENDPOINT.'/carry-forward', [
            'month' => 7,
            'year' => 2026,
        ])->assertOk();

        $before = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $this->assertSame('800.00', $this->findProject($before, $project->id)['previous_monthly_total']);

        $entry->update([
            'daily_income' => 1500,
        ]);
        // monthly total becomes 1300

        $after = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $row = $this->findProject($after, $project->id);
        $this->assertSame('1300.00', $row['previous_monthly_total']);
        $this->assertSame('1300.00', $row['net_cash_fund']);
    }

    public function test_settlement_affects_net_cash_fund_only(): void
    {
        $this->actAs('finance');
        $from = $this->createProject(['name' => 'مساهم']);
        $to = $this->createProject(['name' => 'مستفيد']);

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 2000,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 0,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 0,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 500,
        ]);

        $before = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $this->assertSame('2000.00', $this->findProject($before, $from->id)['monthly_total']);
        $this->assertSame('-500.00', $this->findProject($before, $to->id)['monthly_total']);

        $create = $this->postJson(self::ENDPOINT.'/settlements', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'amount' => 400,
        ])->assertCreated()
            ->assertJsonPath('message', __('messages.cash_station_settlement_created_successfully'));

        $data = $create->json('data');
        $fromRow = $this->findProject($data, $from->id);
        $toRow = $this->findProject($data, $to->id);

        $this->assertSame('2000.00', $fromRow['monthly_total']);
        $this->assertSame('0.00', $fromRow['previous_monthly_total']);
        $this->assertSame('400.00', $fromRow['deducted_contribution']);
        $this->assertSame('0.00', $fromRow['added_contribution']);
        $this->assertSame('1600.00', $fromRow['net_cash_fund']);

        $this->assertSame('-500.00', $toRow['monthly_total']);
        $this->assertSame('400.00', $toRow['added_contribution']);
        $this->assertSame('0.00', $toRow['deducted_contribution']);
        $this->assertSame('-100.00', $toRow['net_cash_fund']);

        $this->assertCount(1, $data['settlements']);
        $settlementId = $data['settlements'][0]['id'];

        $deleted = $this->deleteJson(self::ENDPOINT.'/settlements/'.$settlementId)
            ->assertOk()
            ->assertJsonPath('message', __('messages.cash_station_settlement_deleted_successfully'))
            ->json('data');

        $this->assertSame('2000.00', $this->findProject($deleted, $from->id)['net_cash_fund']);
        $this->assertSame('-500.00', $this->findProject($deleted, $to->id)['net_cash_fund']);
        $this->assertSame([], $deleted['settlements']);
    }

    public function test_settlement_rejected_when_from_project_has_no_surplus(): void
    {
        $this->actAs('finance');
        $from = $this->createProject();
        $to = $this->createProject();

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 0,
            'daily_expense' => 500,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 100,
            'daily_expense' => 0,
        ]);

        $this->postJson(self::ENDPOINT.'/settlements', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'amount' => 50,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.cash_station_settlement_requires_surplus'));
    }

    public function test_settlement_rejected_when_amount_exceeds_transferable_balance(): void
    {
        $this->actAs('finance');
        $from = $this->createProject();
        $to = $this->createProject();

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 200,
            'daily_expense' => 0,
        ]);

        $this->postJson(self::ENDPOINT.'/settlements', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'amount' => 250,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.cash_station_settlement_exceeds_transferable_balance'));
    }

    public function test_settlement_does_not_carry_into_next_month_opening(): void
    {
        $this->actAs('finance');
        $from = $this->createProject();
        $to = $this->createProject();

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 1000,
            'daily_expense' => 0,
        ]);

        $this->postJson(self::ENDPOINT.'/settlements', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'amount' => 400,
        ])->assertCreated();

        $this->postJson(self::ENDPOINT.'/carry-forward', [
            'month' => 7,
            'year' => 2026,
        ])->assertOk();

        $august = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        // Opening uses Monthly Total only (1000), not Net Cash Fund after settlement (600)
        $this->assertSame('1000.00', $this->findProject($august, $from->id)['previous_monthly_total']);
        $this->assertSame('0.00', $this->findProject($august, $to->id)['previous_monthly_total']);
    }

    public function test_surplus_and_deficit_summary_cards_use_monthly_total(): void
    {
        $this->actAs('finance');
        $a = $this->createProject();
        $b = $this->createProject();

        $this->seedEntry($a->id, '2026-07-01', [
            'daily_income' => 1000,
            'daily_expense' => 0,
        ]);
        $this->seedEntry($b->id, '2026-07-01', [
            'daily_income' => 0,
            'daily_expense' => 250,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');

        $this->assertSame('1000.00', $data['summary']['total_monthly_surplus']);
        $this->assertSame('250.00', $data['summary']['total_monthly_deficit']);
    }

    public function test_settlement_does_not_change_surplus_deficit_or_net_month(): void
    {
        $this->actAs('finance');
        $from = $this->createProject(['name' => 'فائض']);
        $to = $this->createProject(['name' => 'عجز']);

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 1000,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 0,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 0,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 500,
        ]);

        $before = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $this->assertSame('1000.00', $before['summary']['total_monthly_surplus']);
        $this->assertSame('500.00', $before['summary']['total_monthly_deficit']);
        $this->assertSame('500.00', $before['summary']['net_month']);
        $this->assertSame('500.00', $before['summary']['net_cash_funds']);

        $after = $this->postJson(self::ENDPOINT.'/settlements', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'amount' => 400,
        ])->assertCreated()->json('data');

        // Surplus/deficit/net_month stay on Monthly Total; only per-project net cash moves.
        $this->assertSame('1000.00', $after['summary']['total_monthly_surplus']);
        $this->assertSame('500.00', $after['summary']['total_monthly_deficit']);
        $this->assertSame('500.00', $after['summary']['net_month']);
        $this->assertSame('500.00', $after['summary']['net_cash_funds']);
        $this->assertSame('600.00', $this->findProject($after, $from->id)['net_cash_fund']);
        $this->assertSame('-100.00', $this->findProject($after, $to->id)['net_cash_fund']);
    }

    public function test_carry_forward_does_not_inflate_next_month_surplus_deficit(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 2000,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 500,
        ]);
        // July monthly total = 1500

        $this->postJson(self::ENDPOINT.'/carry-forward', [
            'month' => 7,
            'year' => 2026,
        ])->assertOk();

        $this->seedEntry($project->id, '2026-08-05', [
            'daily_income' => 300,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_expense' => 50,
        ]);
        // August monthly total = 250

        $august = $this->getJson(self::ENDPOINT.'?month=8&year=2026')->assertOk()->json('data');
        $row = $this->findProject($august, $project->id);

        $this->assertSame('1500.00', $row['previous_monthly_total']);
        $this->assertSame('250.00', $row['monthly_total']);
        $this->assertSame('1750.00', $row['net_cash_fund']);

        // Surplus/deficit use August Monthly Total only; previous appears only in net cash.
        $this->assertSame('250.00', $august['summary']['total_monthly_surplus']);
        $this->assertSame('0.00', $august['summary']['total_monthly_deficit']);
        $this->assertSame('250.00', $august['summary']['net_month']);
        $this->assertSame('1750.00', $august['summary']['net_cash_funds']);
    }

    public function test_summary_administrative_percentage_uses_collected_not_gross_fee(): void
    {
        $this->actAs('finance');
        $eligible = $this->createProject(['name' => 'خاضع']);
        $exempt = $this->createProject([
            'name' => 'معفى',
            'administrative_exempt' => true,
        ]);

        // collected = fee − debt + contribution = 120 − 80 + 30 = 70
        $this->seedEntry($eligible->id, '2026-07-10', [
            'daily_income' => 1000,
            'administrative_fee' => 120,
            'administrative_debt' => 80,
            'contribution' => 30,
            'daily_expense' => 0,
        ]);

        // Exempt fees must not enter the collected summary card.
        $this->seedEntry($exempt->id, '2026-07-10', [
            'daily_income' => 500,
            'administrative_fee' => 50,
            'administrative_debt' => 0,
            'contribution' => 0,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');

        $this->assertSame('70.00', $data['summary']['total_administrative_percentage']);

        // Monthly Total / Net Monthly Result deduct collected only: 1000 − 70 = 930.
        // Unpaid portion (debt-related) is not deducted from monthly_total.
        $this->assertSame('930.00', $this->findProject($data, $eligible->id)['monthly_total']);

        // Exempt fee is ignored for both collected card and monthly_total admin deduction.
        // Exempt monthly_total = 500 − 0 = 500.
        $this->assertSame('500.00', $this->findProject($data, $exempt->id)['monthly_total']);
        $this->assertSame('1430.00', $data['summary']['net_month']);
    }

    public function test_net_month_does_not_deduct_unpaid_administrative_percentage(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // Gross fee 200, of which 80 is unpaid debt and contribution 0 → collected 120.
        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 1000,
            'administrative_fee' => 200,
            'administrative_debt' => 80,
            'contribution' => 0,
            'operational_deduction' => 50,
            'daily_expense' => 100,
            'accumulated_administrative_debt' => 80,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');

        // Net month = 1000 − 120 − 50 − 100 = 730 (not 1000 − 200 − 50 − 100 = 650).
        $this->assertSame('120.00', $data['summary']['total_administrative_percentage']);
        $this->assertSame('730.00', $data['summary']['net_month']);
        $this->assertSame('730.00', $this->findProject($data, $project->id)['monthly_total']);
        $this->assertSame('80.00', $data['summary']['administrative_debts']);
    }

    public function test_status_reflects_net_cash_fund(): void
    {
        $this->actAs('finance');
        $surplus = $this->createProject(['name' => 'فائض']);
        $deficit = $this->createProject(['name' => 'عجز']);
        $balanced = $this->createProject(['name' => 'متوازن']);

        $this->seedEntry($surplus->id, '2026-07-01', [
            'daily_income' => 100,
        ]);
        $this->seedEntry($deficit->id, '2026-07-01', [
            'daily_expense' => 50,
        ]);
        $this->seedEntry($balanced->id, '2026-07-01', [
            'daily_income' => 0,
            'daily_expense' => 0,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');

        $this->assertSame('surplus', $this->findProject($data, $surplus->id)['status']);
        $this->assertSame('deficit', $this->findProject($data, $deficit->id)['status']);
        $this->assertSame('balanced', $this->findProject($data, $balanced->id)['status']);
    }

    public function test_settlement_validation_rejects_same_project_and_non_positive_amount(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->postJson(self::ENDPOINT.'/settlements', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $project->id,
            'to_project_id' => $project->id,
            'amount' => 10,
        ])->assertStatus(422);

        $other = $this->createProject();
        $this->postJson(self::ENDPOINT.'/settlements', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $project->id,
            'to_project_id' => $other->id,
            'amount' => 0,
        ])->assertStatus(422);
    }

    public function test_delete_missing_settlement_returns_404(): void
    {
        $this->actAs('finance');

        $this->deleteJson(self::ENDPOINT.'/settlements/999')
            ->assertNotFound()
            ->assertJsonPath('message', __('messages.cash_station_settlement_not_found'));
    }
}
