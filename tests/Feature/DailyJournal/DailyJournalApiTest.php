<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Support\Facades\Event;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\MonthlySummary\Events\MonthlySummaryUpdated;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;
use Modules\Settings\Services\SettingService;

class DailyJournalApiTest extends DailyJournalFeatureTestCase
{
    public function test_returns_todays_journal_by_default(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject(['name' => 'Active One']);

        $response = $this->getJson('/api/v1/daily-journals');

        $response->assertOk()
            ->assertJsonPath('data.journal_date', now()->toDateString())
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.project.id', $project->id)
            ->assertJsonPath('data.entries.0.daily_income', null);
    }

    public function test_returns_historical_journal_for_given_date(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject();
        $date = now()->subDay()->toDateString();

        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'journal_date' => $date,
            'daily_income' => 500,
            'administrative_fee' => 60,
            'daily_total' => 440,
            'fund_balance' => 440,
        ]);

        $this->getJson('/api/v1/daily-journals?journal_date='.$date)
            ->assertOk()
            ->assertJsonPath('data.journal_date', $date)
            ->assertJsonPath('data.entries.0.daily_income', '500.00');
    }

    public function test_empty_journal_lists_active_projects_with_null_inputs(): void
    {
        $this->actAsFinanceUser();
        $this->createActiveProject();
        $this->createActiveProject();

        $this->getJson('/api/v1/daily-journals')
            ->assertOk()
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.daily_income', null)
            ->assertJsonPath('data.entries.0.daily_expense', null)
            ->assertJsonPath('data.entries.0.contribution', null)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_show_journal_paginates_active_project_entries(): void
    {
        $this->actAsFinanceUser();

        $first = $this->createActiveProject(['name' => 'Project A']);
        $second = $this->createActiveProject(['name' => 'Project B']);
        $third = $this->createActiveProject(['name' => 'Project C']);

        $pageOne = $this->getJson('/api/v1/daily-journals?per_page=2&page=1');
        $pageOne->assertOk()
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.entries.0.project.id', $first->id)
            ->assertJsonPath('data.entries.1.project.id', $second->id);

        $this->assertNotNull($pageOne->json('links.next'));

        $pageTwo = $this->getJson('/api/v1/daily-journals?per_page=2&page=2');
        $pageTwo->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.entries.0.project.id', $third->id)
            ->assertJsonPath('links.next', null);
    }

    public function test_only_active_projects_appear_in_journal(): void
    {
        $this->actAsFinanceUser();
        $active = $this->createActiveProject(['name' => 'Active']);
        Project::factory()->create(['status' => ProjectStatus::Stopped, 'name' => 'Stopped']);
        Project::factory()->archived()->create(['name' => 'Archived']);

        $response = $this->getJson('/api/v1/daily-journals');

        $response->assertOk()->assertJsonCount(1, 'data.entries');
        $this->assertSame($active->id, $response->json('data.entries.0.project.id'));
    }

    public function test_rejects_negative_and_invalid_numeric_values(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject();

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => -1],
            ],
        ])->assertStatus(422);

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => -5],
            ],
        ])->assertStatus(422);

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'contribution' => -2],
            ],
        ])->assertStatus(422);

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 'abc'],
            ],
        ])->assertStatus(422);
    }

    public function test_rejects_attempts_to_update_calculated_fields(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject();

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 100,
                    'administrative_fee' => 999,
                    'fund_balance' => 1,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'entries.0.administrative_fee',
                'entries.0.fund_balance',
            ]);
    }

    public function test_save_recalculates_administrative_fee_and_daily_total(): void
    {
        $this->actAsFinanceUser();
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $project = $this->createActiveProject([
            'administrative_fee_percentage' => 12,
            'administrative_exempt' => false,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => now()->toDateString(),
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 50,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.entries.0.administrative_fee', '120.00')
            ->assertJsonPath('data.entries.0.operational_deduction', '0.00')
            ->assertJsonPath('data.entries.0.administrative_expense', '0.00');

        // 1000 + 0 - 50 - 0 - 120 - 0 = 830
        $this->assertSame('830.00', $response->json('data.entries.0.daily_total'));
        $this->assertSame('830.00', $response->json('data.entries.0.fund_balance'));
    }

    public function test_relative_fixed_and_exempt_operational_deductions(): void
    {
        $this->actAsFinanceUser();
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $relativeA = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => true,
        ]);
        $relativeB = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => true,
        ]);
        $fixed = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => 154,
            'administrative_exempt' => true,
        ]);
        $exempt = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $response = $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $relativeA->id, 'daily_income' => 1000],
                ['project_id' => $relativeB->id, 'daily_income' => 1000],
                ['project_id' => $fixed->id, 'daily_income' => 500],
                ['project_id' => $exempt->id, 'daily_income' => 500],
            ],
        ])->assertOk();

        $byProject = collect($response->json('data.entries'))->keyBy('project.id');

        $this->assertSame('540.50', $byProject[$relativeA->id]['operational_deduction']);
        $this->assertSame('540.50', $byProject[$relativeB->id]['operational_deduction']);
        $this->assertSame('154.00', $byProject[$fixed->id]['operational_deduction']);
        $this->assertSame('0.00', $byProject[$exempt->id]['operational_deduction']);
    }

    public function test_fund_balance_carries_forward_signed_without_creating_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 100],
            ],
        ])->assertOk();

        $this->assertSame(
            100.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $yesterday)
                ->value('fund_balance')
        );

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => null,
                    'daily_expense' => 250,
                    'contribution' => null,
                ],
            ],
        ])->assertOk();

        // previous 100 + daily_total (-250) = -150; debt stays 0
        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);
        $this->assertSame('-150.00', $entry['fund_balance']);
        $this->assertSame('0.00', $entry['administrative_debt']);
        $this->assertSame('0.00', $entry['accumulated_administrative_debt']);

        $this->assertSame(
            100.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $yesterday)
                ->value('fund_balance')
        );

        // Signed negative balance carries into the next journal date.
        $next = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $tomorrow,
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 250],
            ],
        ])->assertOk();

        $nextEntry = collect($next->json('data.entries'))->firstWhere('project.id', $project->id);
        $this->assertSame('100.00', $nextEntry['fund_balance']);
        $this->assertSame('0.00', $nextEntry['administrative_debt']);
    }

    public function test_editing_one_date_does_not_mutate_another_date(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $dayOne = now()->subDays(2)->toDateString();
        $dayTwo = now()->subDay()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $dayOne,
            'entries' => [['project_id' => $project->id, 'daily_income' => 200]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $dayTwo,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $this->patchJson('/api/v1/daily-journals', [
            'journal_date' => $dayTwo,
            'entries' => [['project_id' => $project->id, 'daily_income' => 75]],
        ])->assertOk();

        $this->assertSame(
            200.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $dayOne)
                ->value('daily_income')
        );
        $this->assertSame(
            75.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $dayTwo)
                ->value('daily_income')
        );
    }

    public function test_unauthorized_users_cannot_view_or_save(): void
    {
        $project = $this->createActiveProject();

        $this->getJson('/api/v1/daily-journals')->assertUnauthorized();

        $this->actAsInventoryUser();

        $this->getJson('/api/v1/daily-journals')->assertForbidden();

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [['project_id' => $project->id, 'daily_income' => 10]],
        ])->assertForbidden();
    }

    public function test_finance_and_super_admin_can_access_journal(): void
    {
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->actAsFinanceUser();
        $this->getJson('/api/v1/daily-journals')->assertOk();
        $this->putJson('/api/v1/daily-journals', [
            'entries' => [['project_id' => $project->id, 'daily_income' => 10]],
        ])->assertOk();

        $this->actAsSuperAdmin();
        $this->getJson('/api/v1/daily-journals')->assertOk();
        $this->patchJson('/api/v1/daily-journals', [
            'entries' => [['project_id' => $project->id, 'daily_income' => 20]],
        ])->assertOk();
    }

    public function test_broadcasts_daily_journal_updated_after_successful_save(): void
    {
        Event::fake([DailyJournalUpdated::class]);
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [['project_id' => $project->id, 'daily_income' => 100]],
        ])->assertOk();

        Event::assertDispatched(DailyJournalUpdated::class, function (DailyJournalUpdated $event) use ($project) {
            return $event->journalDate->toDateString() === now()->toDateString()
                && $event->entries->contains(fn ($entry) => $entry->project_id === $project->id);
        });
    }

    public function test_broadcasts_monthly_summary_updated_after_successful_save(): void
    {
        Event::fake([MonthlySummaryUpdated::class]);
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-20',
            'entries' => [['project_id' => $project->id, 'daily_income' => 100]],
        ])->assertOk();

        Event::assertDispatched(MonthlySummaryUpdated::class, function (MonthlySummaryUpdated $event) {
            return $event->year === 2026
                && $event->month === 7
                && isset($event->payload['projects']);
        });
    }

    // ---------------------------------------------------------------
    // Daily Contribution validation tests
    // ---------------------------------------------------------------

    public function test_non_super_admin_cannot_save_positive_contribution(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => 50],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.contribution']);
    }

    public function test_null_or_zero_contribution_from_non_super_admin_is_accepted_when_unchanged(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 100, 'contribution' => null],
            ],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 100, 'contribution' => 0],
            ],
        ])->assertOk();
    }

    public function test_finance_cannot_clear_existing_contribution(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();

        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => 100],
            ],
        ])->assertOk();

        $this->actAsFinanceUser();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => null],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.contribution']);
    }

    public function test_super_admin_contribution_rejected_when_no_deficit(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 1000, 'contribution' => 10],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.contribution']);
    }

    public function test_super_admin_contribution_rejected_when_exceeds_remaining_deficit(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => 200],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.contribution']);
    }

    public function test_super_admin_contribution_rejected_when_exceeds_admin_percentage_balance(): void
    {
        $this->actAsSuperAdmin();
        // Seed only 12 fee (100 income * 12%)
        $this->seedAdminPercentageBalance(100);

        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        // Deficit 150 but available admin balance only 12
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => 100],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.contribution']);
    }

    public function test_super_admin_contribution_accepted_within_remaining_deficit(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => 100],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.available_administrative_percentage_balance', 1100);

        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);

        $this->assertSame('-100.00', $entry['daily_total']);
        $this->assertSame('-50.00', $entry['fund_balance']);
        $this->assertSame('100.00', $entry['administrative_debt']);
        $this->assertSame('100.00', $entry['accumulated_administrative_debt']);
        $this->assertSame('0.00', $entry['administrative_fee']);
        $this->assertSame('0.00', $entry['operational_deduction']);
        $this->assertSame('100.00', $entry['contribution']);

        $this->assertDatabaseHas('admin_percentage_balance_debits', [
            'amount' => '100.00',
        ]);
    }

    public function test_clearing_contribution_does_not_refund_admin_percentage_balance(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => 100],
            ],
        ])->assertOk()
            ->assertJsonPath('data.available_administrative_percentage_balance', 1100);

        $cleared = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => null],
            ],
        ])->assertOk();

        $this->assertSame(1100.0, (float) $cleared->json('data.available_administrative_percentage_balance'));
        $this->assertDatabaseHas('admin_percentage_balance_debits', ['amount' => '100.00']);
    }

    public function test_super_admin_contribution_exactly_equals_remaining_deficit(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 200, 'contribution' => 150],
            ],
        ]);

        $response->assertOk();

        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);

        $this->assertSame('-50.00', $entry['daily_total']);
        $this->assertSame('0.00', $entry['fund_balance']);
        $this->assertSame('150.00', $entry['administrative_debt']);
        $this->assertSame('150.00', $entry['accumulated_administrative_debt']);
        $this->assertSame('0.00', $entry['administrative_fee']);
        $this->assertSame('0.00', $entry['operational_deduction']);
    }

    public function test_contribution_does_not_recalculate_admin_fee_or_op_deduction(): void
    {
        $this->actAsSuperAdmin();
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);
        $this->seedAdminPercentageBalance();

        $project = $this->createActiveProject([
            'administrative_fee_percentage' => 12,
            'administrative_exempt' => false,
            'operational_deduction_type' => OperationalDeductionType::Exempt,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 500]],
        ])->assertOk();

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 100, 'daily_expense' => 800, 'contribution' => 100],
            ],
        ]);

        $response->assertOk();

        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);

        $this->assertSame('12.00', $entry['administrative_fee']);
        $this->assertSame('0.00', $entry['operational_deduction']);
        $this->assertSame('-612.00', $entry['daily_total']);
        $this->assertSame('100.00', $entry['contribution']);
        $this->assertSame('112.00', $entry['administrative_debt']);
        $this->assertSame('-172.00', $entry['fund_balance']);
    }

    public function test_contribution_debt_recomputation_does_not_compound(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();
        \Modules\Project\Models\AdministrativeFeeRate::query()->create([
            'percentage' => 10,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 10,
        ]);

        $first = $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 1000, 'daily_expense' => 2000, 'contribution' => 30],
            ],
        ])->assertOk();

        $entry = collect($first->json('data.entries'))->firstWhere('project.id', $project->id);
        $this->assertSame('-1070.00', $entry['fund_balance']);
        $this->assertSame('130.00', $entry['administrative_debt']);
        $this->assertSame('130.00', $entry['accumulated_administrative_debt']);

        $second = $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 1000, 'daily_expense' => 2000, 'contribution' => 40],
            ],
        ])->assertOk();

        $entry = collect($second->json('data.entries'))->firstWhere('project.id', $project->id);
        $this->assertSame('-1060.00', $entry['fund_balance']);
        $this->assertSame('140.00', $entry['administrative_debt']);
        $this->assertSame('140.00', $entry['accumulated_administrative_debt']);
    }

    public function test_contribution_via_patch_works_with_update_workflow(): void
    {
        $this->actAsSuperAdmin();
        $this->seedAdminPercentageBalance();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [['project_id' => $project->id, 'daily_expense' => 200]],
        ])->assertOk();

        $response = $this->patchJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [['project_id' => $project->id, 'contribution' => 100]],
        ]);

        $response->assertOk();

        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);
        $this->assertSame('100.00', $entry['contribution']);
        $this->assertSame('-100.00', $entry['daily_total']);
    }

    public function test_case1_administrative_fee_covers_deficit_as_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        // Income 200 → fee 24; expense large enough that fund is negative after fee.
        // daily_total = 200 - 0 - 0 - 24 - 0 = 176 if no expense... need deficit.
        // Use expense so fund goes negative while fee is 24.
        $response = $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 200, 'daily_expense' => 300],
            ],
        ])->assertOk();

        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);

        // daily_total = 200 - 300 - 24 = -124; fund = -124
        // Case 1 debt = min(124, 24) = 24
        $this->assertSame('24.00', $entry['administrative_fee']);
        $this->assertSame('-124.00', $entry['fund_balance']);
        $this->assertSame('24.00', $entry['administrative_debt']);
        $this->assertSame('24.00', $entry['accumulated_administrative_debt']);
    }

    public function test_negative_fund_balance_alone_does_not_create_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $response = $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_expense' => 250],
            ],
        ])->assertOk();

        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);

        $this->assertSame('-250.00', $entry['fund_balance']);
        $this->assertSame('0.00', $entry['administrative_fee']);
        $this->assertSame('0.00', $entry['administrative_debt']);
        $this->assertSame('0.00', $entry['accumulated_administrative_debt']);
    }

    public function test_journal_save_does_not_automatically_repay_prior_accumulated_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        // Case 1 debt: income 200 @ 12% = 24 fee; expense 300 → fund -124; debt 24
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 200, 'daily_expense' => 300]],
        ])->assertOk();

        // Surplus day that does not create new debt (income with fee but net positive enough)
        // previous fund -124; income 400, fee 48 → daily = 400-48 = 352; fund = -124+352 = 228
        // fund > 0 → Case 1 debt 0; expense 0 → Case 2 debt 0
        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [['project_id' => $project->id, 'daily_income' => 400]],
        ])->assertOk();

        $entry = collect($response->json('data.entries'))->firstWhere('project.id', $project->id);

        $this->assertSame('228.00', $entry['fund_balance']);
        $this->assertSame('0.00', $entry['administrative_debt']);
        $this->assertSame('24.00', $entry['accumulated_administrative_debt']);
    }

    public function test_explicit_repay_debt_consumes_surplus_against_accumulated_debt(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        // Acc debt 24 from Case 1
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $yesterday,
            'entries' => [['project_id' => $project->id, 'daily_income' => 200, 'daily_expense' => 300]],
        ])->assertOk();

        // Surplus day: fund 228, acc 24
        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [['project_id' => $project->id, 'daily_income' => 400]],
        ])->assertOk();

        $response = $this->postJson('/api/v1/daily-journals/repay-debt', [
            'journal_date' => $today,
            'project_id' => $project->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.fund_balance', '204.00')
            ->assertJsonPath('data.administrative_debt', '0.00')
            ->assertJsonPath('data.accumulated_administrative_debt', '0.00');
    }

    public function test_explicit_repay_debt_rejects_when_no_surplus(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $today = now()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $today,
            'entries' => [['project_id' => $project->id, 'daily_expense' => 100]],
        ])->assertOk();

        $this->postJson('/api/v1/daily-journals/repay-debt', [
            'journal_date' => $today,
            'project_id' => $project->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_explicit_repay_debt_requires_existing_entry(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject();

        $this->postJson('/api/v1/daily-journals/repay-debt', [
            'journal_date' => now()->toDateString(),
            'project_id' => $project->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_inventory_cannot_repay_debt(): void
    {
        $this->actAsInventoryUser();
        $project = $this->createActiveProject();

        $this->postJson('/api/v1/daily-journals/repay-debt', [
            'journal_date' => now()->toDateString(),
            'project_id' => $project->id,
        ])->assertForbidden();
    }
}
