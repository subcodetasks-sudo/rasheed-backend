<?php

namespace Tests\Feature\MonthlySummary;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementUpdated;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;
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

    private const CASH_STATION_ENDPOINT = '/api/v1/cash-station';

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
        $defaults = [
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
        ];

        $merged = array_merge($defaults, $attrs);

        // Match Daily Journal / Monthly Summary tip when tests only seed income/expense.
        if (! array_key_exists('fund_balance', $attrs)) {
            $merged['fund_balance'] = (float) ($merged['daily_income'] ?? 0)
                - (float) ($merged['daily_expense'] ?? 0);
        }

        return DailyJournalEntry::factory()->create($merged);
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

    private function cashStationProject(int $month, int $year, int $projectId): array
    {
        $data = $this->getJson(self::CASH_STATION_ENDPOINT."?month={$month}&year={$year}")
            ->assertOk()
            ->json('data');

        return $this->findProject($data, $projectId);
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

    public function test_show_returns_card_fields_using_fund_balance_tip(): void
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
            // Distinct from monthly_total (800) so the card is proven to use صافي الصندوق.
            'fund_balance' => 650,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=7&year=2026')
            ->assertOk()
            ->assertJsonPath('message', __('messages.monthly_summary_fetched_successfully'))
            ->json('data');

        $row = $this->findProject($data, $project->id);

        $this->assertSame('مشروع أ', $row['project_name']);
        $this->assertSame('إطعام', $row['project_type']);
        $this->assertSame('exempt', $row['project_status']);
        $this->assertSame('650', $row['project_net_result']);
        $this->assertSame('surplus', $row['net_result_state']);
        $this->assertSame('40', $row['administrative_debt']);
        $this->assertSame('0', $row['total_received_contributions']);
        $this->assertSame('0', $row['total_deducted_contributions']);
        $this->assertArrayNotHasKey('net_cash_fund', $row);
        $this->assertArrayNotHasKey('available_surplus', $row);
    }

    /**
     * A sub-whole-unit residual (e.g. -0.07) must not report net_result_state "deficit" while
     * project_net_result displays as "0" - the state is derived from the same whole-unit
     * precision that is shown, so the two never visibly contradict each other.
     */
    public function test_net_result_state_matches_whole_unit_display_for_sub_unit_residual(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-07-10', [
            'daily_expense' => 0.07,
            'fund_balance' => -0.07,
        ]);

        $data = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $row = $this->findProject($data, $project->id);

        $this->assertSame('0', $row['project_net_result']);
        $this->assertSame('neutral', $row['net_result_state']);
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
            'fund_balance' => 1000,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 0,
            'daily_expense' => 400,
            'accumulated_administrative_debt' => 0,
            'fund_balance' => -400,
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

        $this->assertSame('300', $this->findProject($created, $to->id)['total_received_contributions']);
        $this->assertSame('0', $this->findProject($created, $to->id)['total_deducted_contributions']);
        $this->assertSame('300', $this->findProject($created, $from->id)['total_deducted_contributions']);
        $this->assertSame('0', $this->findProject($created, $from->id)['total_received_contributions']);
        // Contributor journal tip is debited; beneficiary tip receives the injection.
        $this->assertSame('700', $this->findProject($created, $from->id)['project_net_result']);
        $this->assertSame('-100', $this->findProject($created, $to->id)['project_net_result']);
        $this->assertCount(1, $created['contributions']);

        $fromCs = $this->cashStationProject(7, 2026, $from->id);
        $toCs = $this->cashStationProject(7, 2026, $to->id);
        $this->assertSame('300', $fromCs['deducted_contribution']);
        $this->assertSame('700', $fromCs['net_cash_fund']);
        $this->assertSame('300', $toCs['added_contribution']);
        $this->assertSame('-100', $toCs['net_cash_fund']);
        $this->assertSame('1000', $fromCs['monthly_total']);
        $this->assertSame('-400', $toCs['monthly_total']);

        $settlementId = $created['contributions'][0]['id'];

        $afterCancel = $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)
            ->assertOk()
            ->json('data');

        $this->assertSame('0', $this->findProject($afterCancel, $to->id)['total_received_contributions']);
        $this->assertSame('0', $this->findProject($afterCancel, $from->id)['total_deducted_contributions']);
        $this->assertSame('1000', $this->findProject($afterCancel, $from->id)['project_net_result']);
        $this->assertSame('-400', $this->findProject($afterCancel, $to->id)['project_net_result']);
        $this->assertSame([], $afterCancel['contributions']);

        $this->assertSame('1000', $this->cashStationProject(7, 2026, $from->id)['net_cash_fund']);
        $this->assertSame('-400', $this->cashStationProject(7, 2026, $to->id)['net_cash_fund']);

        $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)
            ->assertNotFound();
    }

    public function test_fund_deficit_contribution_survives_earlier_day_journal_recalc(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject([
            'category_id' => $category->id,
            'administrative_exempt' => true,
        ]);
        $to = $this->createProject([
            'category_id' => $category->id,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-10',
            'entries' => [
                ['project_id' => $from->id, 'daily_income' => 1000],
                ['project_id' => $to->id, 'daily_expense' => 400],
            ],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-20',
            'entries' => [
                ['project_id' => $to->id, 'daily_income' => 0],
            ],
        ])->assertOk();

        $created = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 300,
        ])->assertCreated()->json('data');

        $this->assertSame('-100', $this->findProject($created, $to->id)['project_net_result']);
        $this->assertSame('700', $this->findProject($created, $from->id)['project_net_result']);
        $this->assertEqualsWithDelta(
            -100.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $to->id)
                ->whereDate('journal_date', '2026-07-20')
                ->value('fund_balance'),
            0.001,
        );
        // Ensure-on-save creates a from-project row on 07-20, so the contributor tip (and debit) is that day.
        $this->assertEqualsWithDelta(
            700.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $from->id)
                ->whereDate('journal_date', '2026-07-20')
                ->value('fund_balance'),
            0.001,
        );

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-10',
            'entries' => [
                ['project_id' => $from->id, 'daily_income' => 1000],
                ['project_id' => $to->id, 'daily_expense' => 400],
            ],
        ])->assertOk();

        $this->assertEqualsWithDelta(
            -100.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $to->id)
                ->whereDate('journal_date', '2026-07-20')
                ->value('fund_balance'),
            0.001,
        );
        $this->assertEqualsWithDelta(
            700.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $from->id)
                ->whereDate('journal_date', '2026-07-20')
                ->value('fund_balance'),
            0.001,
        );

        $afterRecalc = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $this->assertSame('-100', $this->findProject($afterRecalc, $to->id)['project_net_result']);
        $this->assertSame('700', $this->findProject($afterRecalc, $from->id)['project_net_result']);
        $this->assertSame('300', $this->findProject($afterRecalc, $to->id)['total_received_contributions']);
    }

    public function test_administrative_debt_contribution_survives_earlier_day_journal_recalc(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject([
            'category_id' => $category->id,
            'administrative_exempt' => true,
        ]);
        $to = $this->createProject(['category_id' => $category->id]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-10',
            'entries' => [
                ['project_id' => $from->id, 'daily_income' => 800],
                [
                    'project_id' => $to->id,
                    'daily_income' => 1000,
                    'daily_expense' => 2000,
                ],
            ],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-20',
            'entries' => [
                ['project_id' => $to->id, 'daily_income' => 0],
            ],
        ])->assertOk();

        $debtBefore = (float) DailyJournalEntry::query()
            ->where('project_id', $to->id)
            ->whereDate('journal_date', '2026-07-20')
            ->value('accumulated_administrative_debt');
        $this->assertGreaterThan(0, $debtBefore);

        $created = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 50,
        ])->assertCreated()->json('data');

        $expectedDebt = (string) (int) round($debtBefore - 50);
        $this->assertSame($expectedDebt, $this->findProject($created, $to->id)['administrative_debt']);
        $this->assertSame('750', $this->findProject($created, $from->id)['project_net_result']);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-10',
            'entries' => [
                ['project_id' => $from->id, 'daily_income' => 800],
                [
                    'project_id' => $to->id,
                    'daily_income' => 1000,
                    'daily_expense' => 2000,
                ],
            ],
        ])->assertOk();

        $afterRecalc = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $this->assertSame($expectedDebt, $this->findProject($afterRecalc, $to->id)['administrative_debt']);
        $this->assertSame('750', $this->findProject($afterRecalc, $from->id)['project_net_result']);
        $this->assertSame('50', $this->findProject($afterRecalc, $to->id)['total_received_contributions']);
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
            'fund_balance' => 800,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 100,
            'daily_expense' => 0,
            'administrative_debt' => 50,
            'accumulated_administrative_debt' => 120,
            'fund_balance' => 100,
        ]);

        $data = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 80,
        ])->assertCreated()->json('data');

        $this->assertSame('40', $this->findProject($data, $to->id)['administrative_debt']);
        $this->assertSame('80', $this->findProject($data, $to->id)['total_received_contributions']);
        $this->assertSame('80', $this->findProject($data, $from->id)['total_deducted_contributions']);
        $this->assertSame('720', $this->findProject($data, $from->id)['project_net_result']);
        $this->assertSame('100', $this->findProject($data, $to->id)['project_net_result']);

        $settlementId = $data['contributions'][0]['id'];
        $restored = $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)
            ->assertOk()
            ->json('data');

        $this->assertSame('120', $this->findProject($restored, $to->id)['administrative_debt']);
        $this->assertSame('800', $this->findProject($restored, $from->id)['project_net_result']);
        $this->assertSame('0', $this->findProject($restored, $to->id)['total_received_contributions']);
        $this->assertSame('0', $this->findProject($restored, $from->id)['total_deducted_contributions']);
    }

    public function test_administrative_debt_contribution_deleted_via_cash_station_restores(): void
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

        $created = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 80,
        ])->assertCreated()->json('data');

        $this->assertSame('40', $this->findProject($created, $to->id)['administrative_debt']);

        $settlementId = $created['contributions'][0]['id'];

        $this->deleteJson(self::CASH_STATION_ENDPOINT.'/settlements/'.$settlementId)
            ->assertOk()
            ->assertJsonPath('message', __('messages.cash_station_settlement_deleted_successfully'));

        $after = $this->getJson(self::ENDPOINT.'?month=7&year=2026')
            ->assertOk()
            ->json('data');

        $this->assertSame('120', $this->findProject($after, $to->id)['administrative_debt']);
        $this->assertSame('0', $this->findProject($after, $to->id)['total_received_contributions']);
        $this->assertSame('0', $this->findProject($after, $from->id)['total_deducted_contributions']);
        $this->assertSame([], $after['contributions']);
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

        $this->assertSame('0', $row['project_net_result']);
        $this->assertSame('neutral', $row['net_result_state']);
        $this->assertSame('0', $row['administrative_debt']);
        $this->assertSame('0', $row['total_received_contributions']);
        $this->assertSame('0', $row['total_deducted_contributions']);
    }

    public function test_contributor_options_exclude_zero_negative_and_inactive(): void
    {
        $this->actAs('finance');
        $surplus = $this->createProject(['name' => 'فائض']);
        $zero = $this->createProject(['name' => 'صفر']);
        $deficit = $this->createProject(['name' => 'عجز']);
        $inactive = $this->createProject([
            'name' => 'موقوف',
            'status' => ProjectStatus::Stopped,
        ]);

        $this->seedEntry($surplus->id, '2026-07-05', ['daily_income' => 400]);
        $this->seedEntry($zero->id, '2026-07-05', ['daily_income' => 100, 'daily_expense' => 100]);
        $this->seedEntry($deficit->id, '2026-07-05', ['daily_expense' => 250]);
        $this->seedEntry($inactive->id, '2026-07-05', ['daily_income' => 900]);

        $options = $this->getJson(self::ENDPOINT.'/contributor-options?month=7&year=2026')
            ->assertOk()
            ->json('data');

        $byId = collect($options)->keyBy('project_id');
        $this->assertTrue($byId->has($surplus->id));
        $this->assertSame('400', $byId[$surplus->id]['available_surplus']);
        $this->assertFalse($byId->has($zero->id));
        $this->assertFalse($byId->has($deficit->id));
        $this->assertFalse($byId->has($inactive->id));
    }

    public function test_contributor_options_use_fund_balance_tip_not_cash_station_monthly_total(): void
    {
        $this->actAs('finance');
        $project = $this->createProject(['name' => 'صافي مختلف', 'administrative_exempt' => true]);

        // Cash Station monthly_total ≈ 1000; journal صافي الصندوق tip is 650.
        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 1000,
            'daily_expense' => 0,
            'fund_balance' => 650,
        ]);

        $summary = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $this->assertSame('650', $this->findProject($summary, $project->id)['project_net_result']);

        $cashStation = $this->cashStationProject(7, 2026, $project->id);
        $this->assertSame('1000', $cashStation['monthly_total']);
        $this->assertSame('1000', $cashStation['net_cash_fund']);

        $options = collect($this->getJson(self::ENDPOINT.'/contributor-options?month=7&year=2026')
            ->assertOk()
            ->json('data'))->keyBy('project_id');

        $this->assertTrue($options->has($project->id));
        $this->assertSame('650', $options[$project->id]['available_surplus']);
    }

    public function test_contributor_available_surplus_drops_after_contribution(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject(['category_id' => $category->id, 'administrative_exempt' => true]);
        $to = $this->createProject(['category_id' => $category->id, 'administrative_exempt' => true]);

        $this->seedEntry($from->id, '2026-07-12', [
            'daily_income' => 3320,
            'fund_balance' => 3320,
        ]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_expense' => 500,
            'fund_balance' => -500,
        ]);

        $before = collect($this->getJson(self::ENDPOINT.'/contributor-options?month=7&year=2026')
            ->assertOk()
            ->json('data'))->keyBy('project_id');
        $this->assertSame('3320', $before[$from->id]['available_surplus']);

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 320,
        ])->assertCreated();

        $summary = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data');
        $this->assertSame('3000', $this->findProject($summary, $from->id)['project_net_result']);
        $this->assertSame('-180', $this->findProject($summary, $to->id)['project_net_result']);

        $after = collect($this->getJson(self::ENDPOINT.'/contributor-options?month=7&year=2026')
            ->assertOk()
            ->json('data'))->keyBy('project_id');
        $this->assertSame('3000', $after[$from->id]['available_surplus']);
    }

    public function test_beneficiary_options_filter_by_type_category_and_exclude_contributor(): void
    {
        $this->actAs('finance');
        $catA = Category::factory()->create(['name' => 'أ']);
        $catB = Category::factory()->create(['name' => 'ب']);

        $from = $this->createProject(['name' => 'مساهم', 'category_id' => $catA->id]);
        $deficitSame = $this->createProject(['name' => 'عجز', 'category_id' => $catA->id]);
        $debtSame = $this->createProject(['name' => 'دين', 'category_id' => $catA->id]);
        $neutralSame = $this->createProject(['name' => 'محايد', 'category_id' => $catA->id]);
        $deficitOther = $this->createProject(['name' => 'عجز آخر', 'category_id' => $catB->id]);

        $this->seedEntry($from->id, '2026-07-08', ['daily_income' => 1000]);
        $this->seedEntry($deficitSame->id, '2026-07-08', ['daily_expense' => 300]);
        $this->seedEntry($debtSame->id, '2026-07-08', [
            'daily_income' => 50,
            'accumulated_administrative_debt' => 75,
        ]);
        $this->seedEntry($neutralSame->id, '2026-07-08', ['daily_income' => 10]);
        $this->seedEntry($deficitOther->id, '2026-07-08', ['daily_expense' => 300]);

        $fundOpts = collect($this->getJson(
            self::ENDPOINT.'/beneficiary-options?month=7&year=2026'
            .'&from_project_id='.$from->id
            .'&contribution_type='.ContributionType::FundDeficit->value
        )->assertOk()->json('data'))->keyBy('project_id');

        $this->assertTrue($fundOpts->has($deficitSame->id));
        $this->assertSame('300', $fundOpts[$deficitSame->id]['remaining_need']);
        $this->assertFalse($fundOpts->has($from->id));
        $this->assertFalse($fundOpts->has($debtSame->id));
        $this->assertFalse($fundOpts->has($neutralSame->id));
        $this->assertFalse($fundOpts->has($deficitOther->id));

        $debtOpts = collect($this->getJson(
            self::ENDPOINT.'/beneficiary-options?month=7&year=2026'
            .'&from_project_id='.$from->id
            .'&contribution_type='.ContributionType::AdministrativeDebt->value
        )->assertOk()->json('data'))->keyBy('project_id');

        $this->assertTrue($debtOpts->has($debtSame->id));
        $this->assertSame('75', $debtOpts[$debtSame->id]['remaining_need']);
        $this->assertFalse($debtOpts->has($deficitSame->id));
        $this->assertFalse($debtOpts->has($from->id));
        $this->assertFalse($debtOpts->has($deficitOther->id));
    }

    public function test_store_rejects_self_zero_negative_and_inactive(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject(['category_id' => $category->id]);
        $to = $this->createProject(['category_id' => $category->id]);
        $inactiveTo = $this->createProject([
            'category_id' => $category->id,
            'status' => ProjectStatus::Archived,
        ]);

        $this->seedEntry($from->id, '2026-07-03', ['daily_income' => 500]);
        $this->seedEntry($to->id, '2026-07-03', ['daily_expense' => 200]);
        $this->seedEntry($inactiveTo->id, '2026-07-03', ['daily_expense' => 200]);

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $from->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 50,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.monthly_summary_contribution_self_not_allowed'));

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 0,
        ])->assertStatus(422);

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => -10,
        ])->assertStatus(422);

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $inactiveTo->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 50,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.monthly_summary_beneficiary_inactive'));
    }

    public function test_exact_boundary_amounts_and_partial_remaining_need(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject(['category_id' => $category->id]);
        $to = $this->createProject(['category_id' => $category->id]);

        $this->seedEntry($from->id, '2026-07-04', ['daily_income' => 200, 'fund_balance' => 200]);
        $this->seedEntry($to->id, '2026-07-04', ['daily_expense' => 500, 'fund_balance' => -500]);

        // Exact surplus (capped by surplus < remaining need)
        $exactSurplus = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 200,
        ])->assertCreated()->json('data');

        $this->assertSame('200', $this->findProject($exactSurplus, $to->id)['total_received_contributions']);
        $this->assertSame('-300', $this->findProject($exactSurplus, $to->id)['project_net_result']);

        $settlementId = $exactSurplus['contributions'][0]['id'];
        $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)->assertOk();

        // Partial leaves remaining deficit
        $from2 = $this->createProject(['category_id' => $category->id]);
        $this->seedEntry($from2->id, '2026-07-04', ['daily_income' => 300]);

        $partial = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from2->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 150,
        ])->assertCreated()->json('data');

        $beneOpts = collect($this->getJson(
            self::ENDPOINT.'/beneficiary-options?month=7&year=2026'
            .'&from_project_id='.$from2->id
            .'&contribution_type='.ContributionType::FundDeficit->value
        )->assertOk()->json('data'))->keyBy('project_id');

        $this->assertSame('350', $beneOpts[$to->id]['remaining_need']);
        $this->assertSame('150', $this->findProject($partial, $to->id)['total_received_contributions']);
        $this->assertSame('-350', $this->findProject($partial, $to->id)['project_net_result']);
    }

    public function test_exact_administrative_debt_amount_and_partial_remaining_debt(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject(['category_id' => $category->id]);
        $to = $this->createProject(['category_id' => $category->id]);

        $this->seedEntry($from->id, '2026-07-06', ['daily_income' => 500]);
        $this->seedEntry($to->id, '2026-07-06', [
            'daily_income' => 20,
            'accumulated_administrative_debt' => 100,
        ]);

        $exact = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 100,
        ])->assertCreated()->json('data');

        $this->assertSame('0', $this->findProject($exact, $to->id)['administrative_debt']);

        $this->deleteJson(self::ENDPOINT.'/contributions/'.$exact['contributions'][0]['id'])->assertOk();

        $partial = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 40,
        ])->assertCreated()->json('data');

        $this->assertSame('60', $this->findProject($partial, $to->id)['administrative_debt']);

        $debtOpts = collect($this->getJson(
            self::ENDPOINT.'/beneficiary-options?month=7&year=2026'
            .'&from_project_id='.$from->id
            .'&contribution_type='.ContributionType::AdministrativeDebt->value
        )->assertOk()->json('data'))->keyBy('project_id');

        $this->assertSame('60', $debtOpts[$to->id]['remaining_need']);
    }

    public function test_rejects_amount_above_surplus_and_above_remaining_debt(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject(['category_id' => $category->id]);
        $toDeficit = $this->createProject(['category_id' => $category->id]);
        $toDebt = $this->createProject(['category_id' => $category->id]);

        $this->seedEntry($from->id, '2026-07-07', ['daily_income' => 100]);
        $this->seedEntry($toDeficit->id, '2026-07-07', ['daily_expense' => 500]);
        $this->seedEntry($toDebt->id, '2026-07-07', [
            'daily_income' => 10,
            'accumulated_administrative_debt' => 50,
        ]);

        // Surplus 100 < deficit 500 → max 100
        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $toDeficit->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 101,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.monthly_summary_contribution_exceeds_maximum', ['max' => '100.00']));

        // Debt need 50 caps below surplus
        $rich = $this->createProject(['category_id' => $category->id]);
        $this->seedEntry($rich->id, '2026-07-07', ['daily_income' => 400]);

        $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $rich->id,
            'to_project_id' => $toDebt->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 51,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.monthly_summary_contribution_exceeds_maximum', ['max' => '50.00']));
    }

    public function test_fund_deficit_contribution_does_not_change_administrative_debt(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject(['category_id' => $category->id]);
        $to = $this->createProject(['category_id' => $category->id]);

        $this->seedEntry($from->id, '2026-07-09', ['daily_income' => 600, 'fund_balance' => 600]);
        $this->seedEntry($to->id, '2026-07-09', [
            'daily_expense' => 200,
            'accumulated_administrative_debt' => 90,
            'fund_balance' => -200,
        ]);

        $data = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 100,
        ])->assertCreated()->json('data');

        $this->assertSame('90', $this->findProject($data, $to->id)['administrative_debt']);
        $this->assertSame('100', $this->findProject($data, $to->id)['total_received_contributions']);
        $this->assertSame('-100', $this->findProject($data, $to->id)['project_net_result']);
        $this->assertSame('500', $this->findProject($data, $from->id)['project_net_result']);
    }

    public function test_multiple_contributions_and_cancel_isolation(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $fromA = $this->createProject(['name' => 'أ', 'category_id' => $category->id]);
        $fromB = $this->createProject(['name' => 'ب', 'category_id' => $category->id]);
        $to = $this->createProject(['name' => 'مستفيد', 'category_id' => $category->id]);

        $this->seedEntry($fromA->id, '2026-07-11', ['daily_income' => 300, 'fund_balance' => 300]);
        $this->seedEntry($fromB->id, '2026-07-11', ['daily_income' => 400, 'fund_balance' => 400]);
        $this->seedEntry($to->id, '2026-07-11', ['daily_expense' => 500, 'fund_balance' => -500]);

        $first = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $fromA->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 100,
        ])->assertCreated()->json('data');

        $second = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $fromB->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::FundDeficit->value,
            'amount' => 150,
        ])->assertCreated()->json('data');

        $this->assertCount(2, $second['contributions']);
        $this->assertSame('250', $this->findProject($second, $to->id)['total_received_contributions']);
        $this->assertSame('100', $this->findProject($second, $fromA->id)['total_deducted_contributions']);
        $this->assertSame('150', $this->findProject($second, $fromB->id)['total_deducted_contributions']);
        $this->assertSame('-250', $this->findProject($second, $to->id)['project_net_result']);

        $firstId = $first['contributions'][0]['id'];
        $secondId = collect($second['contributions'])->firstWhere('from_project_id', $fromB->id)['id'];

        $afterCancelFirst = $this->deleteJson(self::ENDPOINT.'/contributions/'.$firstId)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $afterCancelFirst['contributions']);
        $this->assertSame($secondId, $afterCancelFirst['contributions'][0]['id']);
        $this->assertSame('150', $this->findProject($afterCancelFirst, $to->id)['total_received_contributions']);
        $this->assertSame('0', $this->findProject($afterCancelFirst, $fromA->id)['total_deducted_contributions']);
        $this->assertSame('150', $this->findProject($afterCancelFirst, $fromB->id)['total_deducted_contributions']);

        $toCs = $this->cashStationProject(7, 2026, $to->id);
        $this->assertSame('150', $toCs['added_contribution']);
        $this->assertSame('-350', $toCs['net_cash_fund']);
        $this->assertSame('-500', $toCs['monthly_total']);
    }

    public function test_contribution_broadcasts_ads_and_daily_journal_updated(): void
    {
        $this->actAs('finance');
        $category = Category::factory()->create();
        $from = $this->createProject(['category_id' => $category->id]);
        $to = $this->createProject(['category_id' => $category->id]);

        $this->seedEntry($from->id, '2026-07-12', ['daily_income' => 800]);
        $this->seedEntry($to->id, '2026-07-12', [
            'daily_income' => 100,
            'administrative_debt' => 50,
            'accumulated_administrative_debt' => 120,
        ]);

        Event::fake([
            DailyJournalUpdated::class,
            AdministrativeDebtSettlementUpdated::class,
            MonthlySummaryUpdated::class,
        ]);

        $created = $this->postJson(self::ENDPOINT.'/contributions', [
            'month' => 7,
            'year' => 2026,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'contribution_type' => ContributionType::AdministrativeDebt->value,
            'amount' => 80,
        ])->assertCreated()->json('data');

        Event::assertDispatched(AdministrativeDebtSettlementUpdated::class, function (AdministrativeDebtSettlementUpdated $event) {
            return $event->year === 2026 && $event->month === 7;
        });
        Event::assertDispatched(DailyJournalUpdated::class, function (DailyJournalUpdated $event) use ($to) {
            return $event->journalDate->toDateString() === '2026-07-12'
                && $event->entries->contains(fn ($entry) => (int) $entry->project_id === $to->id);
        });
        Event::assertDispatched(MonthlySummaryUpdated::class, function (MonthlySummaryUpdated $event) {
            return $event->year === 2026 && $event->month === 7;
        });

        $settlementId = $created['contributions'][0]['id'];

        Event::fake([
            DailyJournalUpdated::class,
            AdministrativeDebtSettlementUpdated::class,
            MonthlySummaryUpdated::class,
        ]);

        $this->deleteJson(self::ENDPOINT.'/contributions/'.$settlementId)->assertOk();

        Event::assertDispatched(AdministrativeDebtSettlementUpdated::class, function (AdministrativeDebtSettlementUpdated $event) {
            return $event->year === 2026 && $event->month === 7;
        });
        Event::assertDispatched(DailyJournalUpdated::class, function (DailyJournalUpdated $event) use ($to) {
            return $event->journalDate->toDateString() === '2026-07-12'
                && $event->entries->contains(fn ($entry) => (int) $entry->project_id === $to->id);
        });
        Event::assertDispatched(MonthlySummaryUpdated::class, function (MonthlySummaryUpdated $event) {
            return $event->year === 2026 && $event->month === 7;
        });
    }

    public function test_current_month_includes_future_in_month_journal_entries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00'));

        try {
            $this->actAs('finance');
            $project = $this->createProject(['name' => 'مشروع مياه نسبية']);

            $this->seedEntry($project->id, '2026-08-17', [
                'daily_income' => 1000,
                'daily_expense' => 0,
                'administrative_fee' => 120,
                'operational_deduction' => 1081,
                'administrative_debt' => 120,
                'accumulated_administrative_debt' => 120,
                'daily_total' => -201,
                'fund_balance' => -81,
            ]);

            $dayOne = $this->getJson(self::ENDPOINT.'?month=8&year=2026')
                ->assertOk()
                ->json('data');

            $this->assertSame('2026-08-31', $dayOne['calculation_date']);
            $rowDayOne = $this->findProject($dayOne, $project->id);
            $this->assertSame('-81', $rowDayOne['project_net_result']);
            $this->assertSame('deficit', $rowDayOne['net_result_state']);
            $this->assertSame('120', $rowDayOne['administrative_debt']);

            $this->seedEntry($project->id, '2026-08-18', [
                'daily_income' => 1000,
                'daily_expense' => 200,
                'administrative_fee' => 120,
                'operational_deduction' => 1081,
                'administrative_debt' => 120,
                'accumulated_administrative_debt' => 240,
                'daily_total' => -401,
                'fund_balance' => -362,
            ]);

            $dayTwo = $this->getJson(self::ENDPOINT.'?month=8&year=2026')
                ->assertOk()
                ->json('data');

            $rowDayTwo = $this->findProject($dayTwo, $project->id);
            $this->assertSame('-362', $rowDayTwo['project_net_result']);
            $this->assertSame('deficit', $rowDayTwo['net_result_state']);
            $this->assertSame('240', $rowDayTwo['administrative_debt']);

            $cs = $this->cashStationProject(8, 2026, $project->id);
            $this->assertSame('-362', $cs['monthly_total']);
            $this->assertSame('-362', $cs['net_cash_fund']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
