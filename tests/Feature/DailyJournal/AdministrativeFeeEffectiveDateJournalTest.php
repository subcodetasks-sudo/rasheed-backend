<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Support\Carbon;
use Modules\Project\Actions\Project\ScheduleAdminFeePercentageChangeAction;

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

    public function test_mid_day_percentage_change_does_not_affect_todays_journal(): void
    {
        $this->actAsFinanceUser();

        $project = $this->createActiveProject([
            'name' => 'Admin Fee Today',
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        app(ScheduleAdminFeePercentageChangeAction::class)->execute($project, 20);

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
            ->assertJsonPath('data.entries.0.administrative_fee', '120');
    }

    public function test_new_percentage_starts_on_next_calendar_day(): void
    {
        $this->actAsFinanceUser();

        $project = $this->createActiveProject([
            'name' => 'Admin Fee Tomorrow',
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        app(ScheduleAdminFeePercentageChangeAction::class)->execute($project, 20);

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
            ->assertJsonPath('data.entries.0.administrative_fee', '200');
    }

    public function test_recalculating_historical_journal_keeps_original_percentage(): void
    {
        $this->actAsFinanceUser();

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
            ->assertJsonPath('data.entries.0.administrative_fee', '120');

        app(ScheduleAdminFeePercentageChangeAction::class)->execute($project, 20);

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
            ->assertJsonPath('data.entries.0.administrative_fee', '120');
    }

    public function test_two_projects_with_different_percentages_get_independent_fees_same_day(): void
    {
        $this->actAsFinanceUser();

        $projectA = $this->createActiveProject([
            'name' => 'Admin Fee Project A',
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);
        $projectB = $this->createActiveProject([
            'name' => 'Admin Fee Project B',
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 10,
        ]);
        $projectC = $this->createActiveProject([
            'name' => 'Admin Fee Project C Exempt',
            'administrative_exempt' => true,
            'administrative_fee_percentage' => 5,
        ]);

        $response = $this->putJson('/api/v1/daily-journals', [
            'journal_date' => '2026-07-30',
            'entries' => [
                ['project_id' => $projectA->id, 'daily_income' => 1000, 'daily_expense' => 0],
                ['project_id' => $projectB->id, 'daily_income' => 1000, 'daily_expense' => 0],
                ['project_id' => $projectC->id, 'daily_income' => 1000, 'daily_expense' => 0],
            ],
        ])->assertOk();

        $byProject = collect($response->json('data.entries'))->keyBy('project.id');

        $this->assertSame('120', $byProject[$projectA->id]['administrative_fee']);
        $this->assertSame('100', $byProject[$projectB->id]['administrative_fee']);
        $this->assertSame('0', $byProject[$projectC->id]['administrative_fee']);
    }
}
