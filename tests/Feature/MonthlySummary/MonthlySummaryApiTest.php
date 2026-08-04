<?php

namespace Tests\Feature\MonthlySummary;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MonthlySummaryApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/monthly-summary';

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

        $this->fail("Project {$projectId} not found in monthly summary response.");
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

    public function test_show_returns_card_fields_using_monthly_total(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create(['name' => 'إطعام']);
        $project = $this->createProject([
            'name' => 'مشروع أ',
            'category_id' => $category->id,
            'administrative_exempt' => true,
        ]);

        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 1000,
            'administrative_fee' => 100,
            'administrative_debt' => 40,
            'contribution' => 0,
            'daily_expense' => 200,
            'accumulated_administrative_debt' => 40,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=7&year=2026')
            ->assertOk()
            ->assertJsonPath('message', __('messages.monthly_summary_fetched_successfully'))
            ->json('data');

        $row = $this->findProject($data, $project->id);

        // Exempt → collected admin 0; monthly_total = 1000 − 0 − 0 − 200 = 800
        $this->assertSame('مشروع أ', $row['project_name']);
        $this->assertSame('إطعام', $row['project_type']);
        $this->assertSame('exempt', $row['project_status']);
        $this->assertSame('800.00', $row['project_net_result']);
        $this->assertSame('surplus', $row['net_result_state']);
        $this->assertSame('40.00', $row['administrative_debt']);
        $this->assertSame('0.00', $row['total_received_contributions']);
        $this->assertArrayNotHasKey('net_cash_fund', $row);
        $this->assertArrayNotHasKey('available_surplus', $row);
    }

    public function test_fund_deficit_contribution_and_cancel(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create(['name' => 'صحي']);
        $from = $this->createProject(['name' => 'فائض', 'category_id' => $category->id]);
        $to = $this->createProject(['name' => 'عجز', 'category_id' => $category->id]);

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 1000,
            'daily_expense' => 0,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 0,
            'daily_expense' => 400,
            'accumulated_administrative_debt' => 0,
        ]);

        $contributors = $this->getJson(self::ENDPOINT.'/contributor-options?month=7&year=2026')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($contributors)->contains(fn ($r) => (int) $r['project_id'] === $from->id));

        $beneficiaries = $this->getJson(
            self::ENDPOINT.'/beneficiary-options?month=7&year=2026'
            .'&from_project_id='.$from->id
            .'&contribution_type='.ContributionType::FundDeficit->value
        )->assertOk()->json('data');
        $this->assertTrue(collect($beneficiaries)->contains(fn ($r) => (int) $r['project_id'] === $to->id));

        $created = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 300,
        ])->assertCreated()
            ->assertJsonPath('message', __('messages.monthly_summary_contribution_created_successfully'))
            ->json('data');

        $this->assertSame('300.00', $this->findProject($created, $to->id)['total_received_contributions']);
        // Net result remains monthly_total (unaffected by contribution)
        $this->assertSame('1000.00', $this->findProject($created, $from->id)['project_net_result']);
        $this->assertSame('-400.00', $this->findProject($created, $to->id)['project_net_result']);
        $this->assertCount(1, $created['contributions']);

        $settlementId = $created['contributions'][0]['id'];

        $afterCancel = $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)
            ->assertOk()
            ->json('data');

        $this->assertSame('0.00', $this->findProject($afterCancel, $to->id)['total_received_contributions']);
        $this->assertSame([], $afterCancel['contributions']);

        $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)
            ->assertNotFound();
    }

    public function test_administrative_debt_contribution_reduces_debt_and_cancel_restores(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create(['name' => 'تعليمي']);
        $from = $this->createProject(['name' => 'مساهم', 'category_id' => $category->id]);
        $to = $this->createProject(['name' => 'مدين', 'category_id' => $category->id]);

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 800,
            'daily_expense' => 0,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 100,
            'daily_expense' => 0,
            'administrative_debt' => 50,
            'accumulated_administrative_debt' => 120,
        ]);

        $data = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 80,
        ])->assertCreated()->json('data');

        $this->assertSame('40.00', $this->findProject($data, $to->id)['administrative_debt']);

        $settlementId = $data['contributions'][0]['id'];
        $restored = $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)
            ->assertOk()
            ->json('data');

        $this->assertSame('120.00', $this->findProject($restored, $to->id)['administrative_debt']);
    }

    public function test_rejects_amount_above_maximum_and_different_category(): void
    {
        $this->actAs('finance');
        $catA = Category::factory()->create(['name' => 'أ']);
        $catB = Category::factory()->create(['name' => 'ب']);
        $from = $this->createProject(['category_id' => $catA->id]);
        $toSame = $this->createProject(['category_id' => $catA->id]);
        $toOther = $this->createProject(['category_id' => $catB->id]);

        $this->seedEntry($from->id, '2026-07-01', ['daily_income' => 500]);
        $this->seedEntry($toSame->id, '2026-07-01', ['daily_expense' => 200]);
        $this->seedEntry($toOther->id, '2026-07-01', ['daily_expense' => 200]);

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $toSame->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 250,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.monthly_summary_contribution_exceeds_maximum', ['max' => '200.00']));

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $toOther->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 50,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.monthly_summary_contribution_different_category'));
    }

    public function test_empty_month_returns_zero_cards(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $row = $this->findProject(
            $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data'),
            $project->id,
        );

        $this->assertSame('0.00', $row['project_net_result']);
        $this->assertSame('neutral', $row['net_result_state']);
        $this->assertSame('0.00', $row['administrative_debt']);
        $this->assertSame('0.00', $row['total_received_contributions']);
    }
}
