<?php

namespace Tests\Feature\CashStation;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\CashStation\Actions\FreezeFinancialMonthAction;
use Modules\CashStation\Models\FinancialMonthLock;
use Modules\CashStation\Models\FinancialMonthProjectSnapshot;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialMonthFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAsFinance(): User
    {
        // Journal writes are super-admin only; this suite drives journal writes
        // through the API, so it acts as super-admin.
        Role::findOrCreate('super-admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function createProject(array $attrs = []): Project
    {
        return Project::factory()->create(array_merge([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 0,
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

    public function test_freeze_command_locks_prior_month_and_serves_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00'));
        $this->actAsFinance();
        $project = $this->createProject(['name' => 'مشروع مجمد']);

        $this->seedEntry($project->id, '2026-08-15', [
            'daily_income' => 1000,
            'daily_expense' => 200,
            'daily_total' => 800,
            'fund_balance' => 800,
        ]);

        Artisan::call('financial-months:freeze-closed');

        $this->assertDatabaseHas('financial_month_locks', [
            'year' => 2026,
            'month' => 8,
        ]);
        $this->assertDatabaseMissing('financial_month_locks', [
            'year' => 2026,
            'month' => 9,
        ]);

        $cs = $this->getJson('/api/v1/cash-station?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->json('data');

        $this->assertNotNull($cs['frozen_at']);
        $row = collect($cs['projects'])->firstWhere('project_id', $project->id);
        $this->assertSame('800', $row['monthly_total']);
        $this->assertSame('800', $row['net_cash_fund']);

        $ms = $this->getJson('/api/v1/monthly-summary?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->json('data');

        $msRow = collect($ms['projects'])->firstWhere('project_id', $project->id);
        $this->assertSame('800', $msRow['project_net_result']);

        Carbon::setTestNow();
    }

    public function test_locked_month_blocks_journal_write_but_current_month_remains_writable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00'));
        $this->actAsFinance();
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-10', [
            'daily_income' => 500,
            'daily_total' => 500,
            'fund_balance' => 500,
        ]);

        Artisan::call('financial-months:freeze-closed');

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-08-10',
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 999, 'daily_expense' => 0],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.financial_month_locked'));

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-09-05',
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 100, 'daily_expense' => 0],
            ],
        ])->assertOk();

        Carbon::setTestNow();
    }

    public function test_locked_month_blocks_cash_station_settlement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00'));
        $this->actAsFinance();
        $from = $this->createProject(['name' => 'من']);
        $to = $this->createProject(['name' => 'إلى']);

        $this->seedEntry($from->id, '2026-08-20', [
            'daily_income' => 2000,
            'daily_total' => 2000,
            'fund_balance' => 2000,
        ]);
        $this->seedEntry($to->id, '2026-08-20', [
            'daily_income' => 0,
            'daily_total' => 0,
            'fund_balance' => 0,
        ]);

        Artisan::call('financial-months:freeze-closed');

        $this->postJson('/api/v1/cash-station/settlements', [
            'year' => 2026,
            'month' => 8,
            'from_project_id' => $from->id,
            'to_project_id' => $to->id,
            'amount' => 100,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.financial_month_locked'));

        Carbon::setTestNow();
    }

    public function test_before_freeze_month_stays_live_and_updates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00'));
        $this->actAsFinance();
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-10', [
            'daily_income' => 100,
            'daily_total' => 100,
            'fund_balance' => 100,
        ]);

        $before = $this->getJson('/api/v1/cash-station?month=8&year=2026')
            ->assertOk()
            ->assertJsonPath('data.locked', false)
            ->json('data');

        $rowBefore = collect($before['projects'])->firstWhere('project_id', $project->id);
        $this->assertSame('100', $rowBefore['monthly_total']);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-08-10',
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 250, 'daily_expense' => 0],
            ],
        ])->assertOk();

        $after = $this->getJson('/api/v1/cash-station?month=8&year=2026')->json('data');
        $rowAfter = collect($after['projects'])->firstWhere('project_id', $project->id);
        $this->assertSame('250', $rowAfter['monthly_total']);

        Carbon::setTestNow();
    }

    public function test_freeze_is_idempotent_and_snapshot_stable_after_later_activity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00'));
        $this->actAsFinance();
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-12', [
            'daily_income' => 700,
            'daily_total' => 700,
            'fund_balance' => 700,
        ]);

        /** @var FreezeFinancialMonthAction $freeze */
        $freeze = app(FreezeFinancialMonthAction::class);
        $first = $freeze->execute(2026, 8);
        $second = $freeze->execute(2026, 8);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FinancialMonthLock::query()->where('year', 2026)->where('month', 8)->count());
        $this->assertSame(
            1,
            FinancialMonthProjectSnapshot::query()->where('year', 2026)->where('month', 8)->where('project_id', $project->id)->count()
        );

        $frozenCs = $this->getJson('/api/v1/cash-station?month=8&year=2026')->json('data');
        $frozenMs = $this->getJson('/api/v1/monthly-summary?month=8&year=2026')->json('data');
        $netCash = collect($frozenCs['projects'])->firstWhere('project_id', $project->id)['net_cash_fund'];
        $netResult = collect($frozenMs['projects'])->firstWhere('project_id', $project->id)['project_net_result'];

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-09-02',
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 5000, 'daily_expense' => 0],
            ],
        ])->assertOk();

        $csAgain = $this->getJson('/api/v1/cash-station?month=8&year=2026')->json('data');
        $msAgain = $this->getJson('/api/v1/monthly-summary?month=8&year=2026')->json('data');

        $this->assertSame($netCash, collect($csAgain['projects'])->firstWhere('project_id', $project->id)['net_cash_fund']);
        $this->assertSame($netResult, collect($msAgain['projects'])->firstWhere('project_id', $project->id)['project_net_result']);

        Carbon::setTestNow();
    }

    public function test_carry_forward_from_locked_month_into_unlocked_next_month_is_allowed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00'));
        $this->actAsFinance();
        $project = $this->createProject();

        $this->seedEntry($project->id, '2026-08-15', [
            'daily_income' => 400,
            'daily_total' => 400,
            'fund_balance' => 400,
        ]);

        Artisan::call('financial-months:freeze-closed');

        $this->postJson('/api/v1/cash-station/carry-forward', [
            'month' => 8,
            'year' => 2026,
        ])->assertOk();

        $this->getJson('/api/v1/cash-station?month=9&year=2026')
            ->assertOk()
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.carried_forward_from_previous', true);

        Carbon::setTestNow();
    }
}
