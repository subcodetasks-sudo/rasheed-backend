<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Support\Carbon;
use Modules\Settings\Actions\UpdateSystemGeneralSettingsAction;
use Modules\Settings\Models\SystemGeneralSetting;

class AdministrativeFeeEffectiveDateJournalTest extends DailyJournalFeatureTestCase
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
        SystemGeneralSetting::singleton()->update(['admin_fee_percentage' => 12]);

        $project = $this->createActiveProject([
            'name' => 'Admin Fee Today',
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        app(UpdateSystemGeneralSettingsAction::class)->execute(['admin_fee_percentage' => 20]);

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
            ->assertJsonPath('data.entries.0.administrative_fee', '120.00');
    }

    public function test_new_admin_fee_starts_on_next_calendar_day(): void
    {
        $this->actAsFinanceUser();
        SystemGeneralSetting::singleton()->update(['admin_fee_percentage' => 12]);

        $project = $this->createActiveProject([
            'name' => 'Admin Fee Tomorrow',
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        app(UpdateSystemGeneralSettingsAction::class)->execute(['admin_fee_percentage' => 20]);

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
            ->assertJsonPath('data.entries.0.administrative_fee', '200.00');
    }

    public function test_recalculating_historical_journal_keeps_original_percentage(): void
    {
        $this->actAsFinanceUser();
        SystemGeneralSetting::singleton()->update(['admin_fee_percentage' => 12]);

        $project = $this->createActiveProject([
            'name' => 'Admin Fee Historical',
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-15',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.entries.0.administrative_fee', '120.00');

        app(UpdateSystemGeneralSettingsAction::class)->execute(['admin_fee_percentage' => 20]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-15',
            'entries' => [
                [
                    'project_id' => $project->id,
                    'daily_income' => 1000,
                    'daily_expense' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.entries.0.administrative_fee', '120.00');
    }
}
