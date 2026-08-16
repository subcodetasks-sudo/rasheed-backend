<?php

namespace Tests\Feature\AdministrativeDebtSettlement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementUpdated;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\CashStation\Events\CashStationUpdated;
use Modules\CashStation\Models\CashStationMonthCarry;
use Modules\DailyJournal\Models\AdminPercentageBalanceCredit;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\AdministrativePercentageBalanceService;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\Settings\Models\SystemGeneralSetting;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrativeDebtSettlementApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/administrative-debt-settlements';

    private const CS_ENDPOINT = '/api/v1/cash-station';

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
        $merged = array_merge([
            'project_id' => $projectId,
            'journal_date' => $date,
            'daily_income' => 0,
            'daily_expense' => 0,
            'contribution' => 0,
            'administrative_expense' => 0,
            'uncovered_administrative_expense' => 0,
            'project_administration_settled' => 0,
            'administrative_fee' => 0,
            'operational_deduction' => 0,
            'daily_total' => 0,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
        ], $attrs);

        // Settlement eligibility now reads surplus from the Daily Journal's own fund_balance (single
        // source of truth) instead of recomputing from these components, so tests must seed a fund_balance
        // consistent with what DailyJournalCalculationService would actually produce, unless a test
        // explicitly overrides fund_balance itself (e.g. to model a multi-day carry-forward chain).
        if (! array_key_exists('fund_balance', $attrs)) {
            $covered = (float) $merged['administrative_expense'] - (float) $merged['uncovered_administrative_expense'];
            $merged['fund_balance'] = round(
                (float) $merged['daily_income']
                + (float) $merged['contribution']
                - (float) $merged['daily_expense']
                - (float) $merged['administrative_fee']
                - (float) $merged['operational_deduction']
                - $covered
                - (float) $merged['project_administration_settled'],
                2
            );
        }

        return DailyJournalEntry::factory()->create($merged);
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

    public function test_list_only_includes_projects_with_debt(): void
    {
        $this->actAs('finance');
        $indebted = $this->createProject(['name' => 'مدين']);
        $clean = $this->createProject(['name' => 'نظيف']);

        // Monthly total = 1000 - 100 = 900 surplus; debt 100
        $this->seedEntry($indebted->id, '2026-07-15', [
            'daily_income' => 1000,
            'administrative_fee' => 100,
            'administrative_debt' => 100,
            'accumulated_administrative_debt' => 100,
        ]);

        $this->seedEntry($clean->id, '2026-07-15', [
            'daily_income' => 500,
            'administrative_fee' => 50,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
        ]);

        $response = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk();
        $data = $response->json('data');
        $projects = $data['projects'];

        $this->assertArrayNotHasKey('summary', $data);
        $this->assertArrayNotHasKey('available_administrative_percentage_balance', $data);

        $this->assertCount(1, $projects);
        $this->assertSame([
            'project_id',
            'project_name',
            'net_cash_balance',
            'administrative_debt',
            'recoverable_amount',
            'remaining_debt',
            'settlement_status',
            'can_settle',
        ], array_keys($projects[0]));
        $this->assertSame($indebted->id, $projects[0]['project_id']);
        $this->assertSame('100.00', $projects[0]['administrative_debt']);
        $this->assertSame('100.00', $projects[0]['recoverable_amount']);
        $this->assertSame('100.00', $projects[0]['remaining_debt']);
        $this->assertTrue($projects[0]['can_settle']);
        $this->assertSame('unpaid', $projects[0]['settlement_status']);
    }

    public function test_rejects_settle_without_surplus(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // Monthly total = 0 - 50 fee collected? fee-debt+contrib for admin % = 50-50=0
        // monthly total = 0 - 0 - 0 - 0 = 0; debt 50; no surplus
        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 0,
            'administrative_fee' => 50,
            'administrative_debt' => 50,
            'accumulated_administrative_debt' => 50,
        ]);

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.administrative_debt_settlement_requires_surplus'));
    }

    public function test_recoverable_caps_and_partial_then_paid(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // Collected admin % = fee − debt = 0 → Monthly Total = 50; debt 100 → recoverable 50
        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 50,
            'administrative_fee' => 100,
            'administrative_debt' => 100,
            'accumulated_administrative_debt' => 100,
        ]);

        $list = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects.0');
        $this->assertSame('100.00', $list['administrative_debt']);
        $this->assertSame('50.00', $list['recoverable_amount']);
        $this->assertSame('100.00', $list['remaining_debt']);

        $csBefore = $this->getJson(self::CS_ENDPOINT.'?month=7&year=2026')->assertOk();
        $netBefore = collect($csBefore->json('data.projects'))
            ->firstWhere('project_id', $project->id)['net_cash_fund'];
        $this->assertSame('100.00', collect($csBefore->json('data.projects'))
            ->firstWhere('project_id', $project->id)['remaining_administrative_debt']);

        $availableBefore = app(AdministrativePercentageBalanceService::class)->availableBalance();

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
            'amount' => 20,
        ])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.administrative_debt_settlement_created_successfully'));

        $this->assertDatabaseHas('administrative_debt_settlements', [
            'project_id' => $project->id,
            'year' => 2026,
            'month' => 7,
            'recoverable_amount' => '20.00',
            'allocated_current_debt' => '20.00',
            'status' => 'partial',
        ]);
        $this->assertDatabaseHas('admin_percentage_balance_credits', [
            'amount' => '20.00',
        ]);

        $availableAfterPartial = app(AdministrativePercentageBalanceService::class)->availableBalance();
        $this->assertEqualsWithDelta($availableBefore + 20, $availableAfterPartial, 0.01);

        $csAfter = $this->getJson(self::CS_ENDPOINT.'?month=7&year=2026')->assertOk();
        $projectCs = collect($csAfter->json('data.projects'))->firstWhere('project_id', $project->id);
        $this->assertSame($netBefore, $projectCs['net_cash_fund']);
        $this->assertSame('80.00', $projectCs['remaining_administrative_debt']);
        $this->assertSame('surplus', $projectCs['status']);

        $entryAfterPartial = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', '2026-07-15')
            ->first();
        $this->assertSame('80.00', number_format((float) $entryAfterPartial->accumulated_administrative_debt, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $entryAfterPartial->administrative_debt, 2, '.', ''));

        $afterPartial = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects.0');
        $this->assertSame('80.00', $afterPartial['administrative_debt']);
        $this->assertSame('80.00', $afterPartial['remaining_debt']);
        $this->assertSame('30.00', $afterPartial['recoverable_amount']);
        $this->assertSame('partial', $afterPartial['settlement_status']);

        // Default amount = full remaining recoverable (min(80, 30) = 30)
        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
        ])->assertCreated();

        $afterRecoverable = $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects');
        $this->assertCount(1, $afterRecoverable);
        $this->assertSame('50.00', $afterRecoverable[0]['administrative_debt']);
        $this->assertSame('0.00', $afterRecoverable[0]['recoverable_amount']);
        $this->assertFalse($afterRecoverable[0]['can_settle']);
        $this->assertSame('partial', $afterRecoverable[0]['settlement_status']);

        // Cumulative surplus exhausted — further settle rejected
        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
            'amount' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.administrative_debt_settlement_requires_surplus'));

        $this->assertSame(2, AdminPercentageBalanceCredit::query()->count());
        $this->assertEqualsWithDelta(
            $availableBefore + 50,
            app(AdministrativePercentageBalanceService::class)->availableBalance(),
            0.01,
        );
    }

    public function test_allocation_priority_current_then_carried(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-06-20', [
            'daily_income' => 100,
            'administrative_fee' => 80,
            'administrative_debt' => 80,
            'accumulated_administrative_debt' => 80,
        ]);

        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 500,
            'administrative_fee' => 40,
            'administrative_debt' => 40,
            'accumulated_administrative_debt' => 120,
        ]);

        CashStationMonthCarry::query()->create([
            'from_year' => 2026,
            'from_month' => 6,
            'to_year' => 2026,
            'to_month' => 7,
            'carried_by' => null,
        ]);

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
            'amount' => 50,
        ])->assertCreated();

        $this->assertDatabaseHas('administrative_debt_settlements', [
            'project_id' => $project->id,
            'allocated_cash_box' => '0.00',
            'allocated_current_debt' => '40.00',
            'allocated_carried_debt' => '10.00',
            'recoverable_amount' => '50.00',
        ]);
    }

    public function test_full_debt_settlement_reaches_paid_when_surplus_covers_debt(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 200,
            'administrative_fee' => 80,
            'administrative_debt' => 80,
            'accumulated_administrative_debt' => 80,
        ]);

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
        ])->assertCreated();

        $this->assertDatabaseHas('administrative_debt_settlements', [
            'project_id' => $project->id,
            'recoverable_amount' => '80.00',
            'status' => 'paid',
        ]);

        $this->assertCount(
            0,
            $this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects'),
        );

        $entry = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', '2026-07-15')
            ->first();
        $this->assertSame('80.00', number_format((float) $entry->administrative_debt, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $entry->accumulated_administrative_debt, 2, '.', ''));

        // A blank later day inherits zero accumulated from the settled tip.
        $this->seedEntry($project->id, '2026-07-16', [
            'daily_income' => 10,
            'administrative_fee' => 0,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
            'fund_balance' => 10,
        ]);
        $this->assertSame(
            '0.00',
            number_format((float) DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', '2026-07-15')
                ->value('accumulated_administrative_debt'), 2, '.', ''),
        );

        $cs = collect($this->getJson(self::CS_ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects'))
            ->firstWhere('project_id', $project->id);
        $this->assertSame('0.00', $cs['remaining_administrative_debt']);
        $this->assertSame('80.00', $cs['administrative_debt']);
    }

    public function test_exceeds_surplus_rejected(): void
    {
        $this->actAs('super-admin');
        $project = $this->createProject();

        // Monthly Total = 50; debt 100 → amount 80 is ≤ debt but > surplus
        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 50,
            'administrative_fee' => 100,
            'administrative_debt' => 100,
            'accumulated_administrative_debt' => 100,
        ]);

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
            'amount' => 80,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.administrative_debt_settlement_exceeds_surplus'));
    }

    public function test_journal_update_broadcasts_subsequent_carried_months(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-06-10', [
            'daily_income' => 300,
            'administrative_fee' => 30,
            'accumulated_administrative_debt' => 0,
        ]);
        $this->seedEntry($project->id, '2026-07-10', [
            'daily_income' => 100,
            'administrative_fee' => 10,
            'accumulated_administrative_debt' => 0,
        ]);
        $this->seedEntry($project->id, '2026-08-10', [
            'daily_income' => 50,
            'administrative_fee' => 5,
            'accumulated_administrative_debt' => 0,
        ]);

        $this->postJson(self::CS_ENDPOINT.'/carry-forward', ['month' => 6, 'year' => 2026])->assertOk();
        $this->postJson(self::CS_ENDPOINT.'/carry-forward', ['month' => 7, 'year' => 2026])->assertOk();

        Event::fake([CashStationUpdated::class, AdministrativeDebtSettlementUpdated::class]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-06-10',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 400,
                    'daily_expense' => 0,
                    'contribution' => 0,
                ],
            ],
        ])->assertOk();

        Event::assertDispatched(CashStationUpdated::class, function (CashStationUpdated $event) {
            return $event->year === 2026 && $event->month === 6;
        });
        Event::assertDispatched(CashStationUpdated::class, function (CashStationUpdated $event) {
            return $event->year === 2026 && $event->month === 7;
        });
        Event::assertDispatched(CashStationUpdated::class, function (CashStationUpdated $event) {
            return $event->year === 2026 && $event->month === 8;
        });
        Event::assertDispatched(AdministrativeDebtSettlementUpdated::class, function (AdministrativeDebtSettlementUpdated $event) {
            return $event->year === 2026 && $event->month === 6
                && isset($event->payload['projects'])
                && ! isset($event->payload['summary']);
        });
    }

    public function test_journal_contribution_broadcasts_administrative_debt_settlement_list(): void
    {
        $this->actAs('super-admin');
        $feeSource = $this->createProject(['administrative_exempt' => false]);
        $project = $this->createProject([
            'administrative_exempt' => true,
            'name' => 'مساهمة',
        ]);

        $this->seedEntry($feeSource->id, '2026-07-01', [
            'daily_income' => 10000,
            'administrative_fee' => 1200,
            'administrative_debt' => 0,
            'accumulated_administrative_debt' => 0,
            'fund_balance' => 8800,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-14',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 50,
                    'daily_expense' => 0,
                    'contribution' => 0,
                ],
            ],
        ])->assertOk();

        Event::fake([AdministrativeDebtSettlementUpdated::class]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-15',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 0,
                    'daily_expense' => 200,
                    'contribution' => 100,
                ],
            ],
        ])->assertOk();

        Event::assertDispatched(AdministrativeDebtSettlementUpdated::class, function (AdministrativeDebtSettlementUpdated $event) use ($project) {
            if ($event->year !== 2026 || $event->month !== 7) {
                return false;
            }

            $row = collect($event->payload['projects'] ?? [])->firstWhere('project_id', $project->id);

            return $row !== null
                && (float) $row['administrative_debt'] >= 100
                && ! isset($event->payload['summary']);
        });
    }

    public function test_settle_broadcasts_monthly_summary_updated(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 50,
            'administrative_fee' => 100,
            'administrative_debt' => 100,
            'accumulated_administrative_debt' => 100,
        ]);

        Event::fake([MonthlySummaryUpdated::class]);

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
            'amount' => 20,
        ])->assertCreated();

        Event::assertDispatched(MonthlySummaryUpdated::class, function (MonthlySummaryUpdated $event) {
            return $event->year === 2026
                && $event->month === 7
                && isset($event->payload['projects']);
        });
    }

    public function test_settlement_uses_project_specific_accrued_debt_not_global_percentage(): void
    {
        $this->actAs('finance');

        // Global default is 12% → 120 on 1000 income. Projects below use 20% / 5%.
        SystemGeneralSetting::singleton()->update(['admin_fee_percentage' => 12]);

        $high = $this->createProject([
            'name' => 'High rate project',
            'administrative_fee_percentage' => 20,
        ]);
        $low = $this->createProject([
            'name' => 'Low rate project',
            'administrative_fee_percentage' => 5,
        ]);

        // Persist fees/debt as produced by each project's own percentage on 1000 income
        // (200 and 50). Settlement must expose and recover those amounts — never 120.
        $this->seedEntry($high->id, '2026-07-15', [
            'daily_income' => 1000,
            'administrative_fee' => 200,
            'administrative_debt' => 200,
            'accumulated_administrative_debt' => 200,
        ]);
        $this->seedEntry($low->id, '2026-07-15', [
            'daily_income' => 1000,
            'administrative_fee' => 50,
            'administrative_debt' => 50,
            'accumulated_administrative_debt' => 50,
        ]);

        $listed = collect($this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects'))
            ->keyBy('project_id');

        $this->assertEqualsWithDelta(200.0, (float) $listed[$high->id]['administrative_debt'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $listed[$high->id]['recoverable_amount'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $listed[$low->id]['administrative_debt'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $listed[$low->id]['recoverable_amount'], 0.001);

        // Explicitly not the global 12% of 1000 income.
        $this->assertNotEqualsWithDelta(120.0, (float) $listed[$high->id]['administrative_debt'], 0.001);
        $this->assertNotEqualsWithDelta(120.0, (float) $listed[$low->id]['administrative_debt'], 0.001);

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $high->id,
        ])->assertCreated();

        $settlement = AdministrativeDebtSettlement::query()
            ->where('project_id', $high->id)
            ->where('year', 2026)
            ->where('month', 7)
            ->first();

        $this->assertNotNull($settlement);
        $this->assertEqualsWithDelta(200.0, (float) $settlement->recoverable_amount, 0.001);
        $this->assertSame('paid', $settlement->status->value);
        $this->assertNotEqualsWithDelta(120.0, (float) $settlement->recoverable_amount, 0.001);

        $remaining = collect($this->getJson(self::ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects'))
            ->keyBy('project_id');

        $this->assertArrayNotHasKey($high->id, $remaining->all());
        $this->assertEqualsWithDelta(50.0, (float) $remaining[$low->id]['administrative_debt'], 0.001);
    }

    public function test_rejects_settle_when_surplus_is_consumed_by_administrative_expense_coverage(): void
    {
        $this->actAs('finance');
        $project = $this->createProject();

        // income 200 fully absorbed by same-day administrative_expense coverage (fund_balance = 0),
        // while an unrelated accumulated debt of 100 remains from a prior day. Net cash has no real
        // surplus to settle with, even though income alone would look like a 200 surplus.
        $this->seedEntry($project->id, '2026-07-15', [
            'daily_income' => 200,
            'administrative_expense' => 200,
            'uncovered_administrative_expense' => 0,
            'accumulated_administrative_debt' => 100,
            'fund_balance' => 0,
        ]);

        $csRow = collect($this->getJson(self::CS_ENDPOINT.'?month=7&year=2026')->assertOk()->json('data.projects'))
            ->firstWhere('project_id', $project->id);
        $this->assertSame('0', $csRow['net_cash_fund']);
        $this->assertSame('balanced', $csRow['status']);

        $this->postJson(self::ENDPOINT, [
            'year' => 2026,
            'month' => 7,
            'project_id' => $project->id,
            'amount' => 50,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.administrative_debt_settlement_requires_surplus'));
    }
}
