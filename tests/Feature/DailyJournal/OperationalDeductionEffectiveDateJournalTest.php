<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Support\Carbon;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Settings\Actions\UpsertMonthlyEmployeeSettingsAction;
use Modules\Settings\Services\SettingService;

class OperationalDeductionEffectiveDateJournalTest extends DailyJournalFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:45:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mid_day_setting_change_does_not_affect_todays_journal(): void
    {
        $this->actAsFinanceUser();
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $project = $this->createActiveProject([
            'name' => 'Relative Today',
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => true,
        ]);

        $this->upsertRelativePool(2000);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-30',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.entries.0.operational_deduction', '1081.00');
    }

    public function test_new_deduction_starts_on_next_calendar_day(): void
    {
        $this->actAsFinanceUser();
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $project = $this->createActiveProject([
            'name' => 'Relative Tomorrow',
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => true,
        ]);

        $this->upsertRelativePool(2000);

        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00:00'));

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-31',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.entries.0.operational_deduction', '2000.00');
    }

    public function test_recalculating_historical_journal_keeps_original_pool(): void
    {
        $this->actAsFinanceUser();
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $project = $this->createActiveProject([
            'name' => 'Historical Relative',
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-29',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.entries.0.operational_deduction', '1081.00');

        $this->upsertRelativePool(2000);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-29',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.entries.0.operational_deduction', '1081.00');

        $this->assertSame(
            '1081.00',
            (string) DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', '2026-07-29')
                ->value('operational_deduction')
        );
    }

    public function test_old_and_new_projects_share_next_day_pool(): void
    {
        $this->actAsFinanceUser();
        app(SettingService::class)->update('total_operational_deduction', 1081, 'decimal', true);

        $oldProject = $this->createActiveProject([
            'name' => 'Old Relative',
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => true,
        ]);

        $this->upsertRelativePool(2000);

        $newProject = $this->createActiveProject([
            'name' => 'New Relative',
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00:00'));

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-31',
            'entries' => [
                [
                    'project_id' => $oldProject->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
                [
                    'project_id' => $newProject->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
            ],
        ])->assertOk();

        $byId = collect($response->json('data.entries'))->keyBy('project.id');

        $this->assertSame('1000.00', $byId[$oldProject->id]['operational_deduction']);
        $this->assertSame('1000.00', $byId[$newProject->id]['operational_deduction']);
    }

    private function upsertRelativePool(float $relative): void
    {
        app(UpsertMonthlyEmployeeSettingsAction::class)->execute(7, 2026, [
            'fixed_workers' => $relative,
            'media_staff' => 0,
            'administrative_staff' => 0,
            'variable_workers' => 0,
            'speakers' => 0,
            'cooks' => 0,
        ]);
    }
}
