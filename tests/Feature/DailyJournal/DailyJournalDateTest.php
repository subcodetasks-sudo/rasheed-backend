<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Database\QueryException;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Enums\OperationalDeductionType;

class DailyJournalDateTest extends DailyJournalFeatureTestCase
{
    public function test_creating_journal_with_explicit_journal_date(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $businessDate = '2026-07-27';

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $businessDate,
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 250],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.journal_date', $businessDate)
            ->assertJsonPath('data.entries.0.journal_date', $businessDate)
            ->assertJsonPath('data.entries.0.daily_income', '250.00');

        $entry = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', $businessDate)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($businessDate, $entry->journal_date->toDateString());
        $this->assertNotSame($businessDate, $entry->created_at->toDateString());
    }

    public function test_creating_journal_without_date_defaults_to_today(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'entries' => [
                ['project_id' => $project->id, 'daily_income' => 80],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.journal_date', now()->toDateString());

        $this->assertTrue(
            DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', now()->toDateString())
                ->exists()
        );
    }

    public function test_retrieving_todays_and_historical_journals_use_journal_date(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $historical = now()->subDays(3)->toDateString();

        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'journal_date' => $historical,
            'daily_income' => 400,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/daily-journals')
            ->assertOk()
            ->assertJsonPath('data.journal_date', now()->toDateString())
            ->assertJsonPath('data.entries.0.daily_income', null);

        $this->getJson('/api/v1/daily-journals?journal_date='.$historical)
            ->assertOk()
            ->assertJsonPath('data.journal_date', $historical)
            ->assertJsonPath('data.entries.0.daily_income', '400.00');
    }

    public function test_created_at_does_not_affect_journal_retrieval(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $businessDate = now()->subDays(5)->toDateString();

        $entry = DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'journal_date' => $businessDate,
            'daily_income' => 123,
        ]);

        // Audit timestamps intentionally set to "today" — retrieval must still use journal_date.
        $entry->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->getJson('/api/v1/daily-journals')
            ->assertOk()
            ->assertJsonPath('data.journal_date', now()->toDateString())
            ->assertJsonPath('data.entries.0.daily_income', null);

        $this->getJson('/api/v1/daily-journals?journal_date='.$businessDate)
            ->assertOk()
            ->assertJsonPath('data.journal_date', $businessDate)
            ->assertJsonPath('data.entries.0.daily_income', '123.00');
    }

    public function test_updating_only_selected_business_date(): void
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
            'entries' => [['project_id' => $project->id, 'daily_income' => 10]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $dayTwo,
            'entries' => [['project_id' => $project->id, 'daily_income' => 20]],
        ])->assertOk();

        $this->patchJson('/api/v1/daily-journals', [
            'journal_date' => $dayTwo,
            'entries' => [['project_id' => $project->id, 'daily_income' => 35]],
        ])->assertOk();

        $this->assertSame(
            10.0,
            (float) DailyJournalEntry::query()->whereDate('journal_date', $dayOne)->where('project_id', $project->id)->value('daily_income')
        );
        $this->assertSame(
            35.0,
            (float) DailyJournalEntry::query()->whereDate('journal_date', $dayTwo)->where('project_id', $project->id)->value('daily_income')
        );
    }

    public function test_prevents_duplicate_records_for_same_project_and_journal_date(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $journalDate = now()->subDay()->toDateString();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $journalDate,
            'entries' => [['project_id' => $project->id, 'daily_income' => 50]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $journalDate,
            'entries' => [['project_id' => $project->id, 'daily_income' => 75]],
        ])->assertOk();

        $this->assertSame(
            1,
            DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $journalDate)
                ->count()
        );

        $this->assertSame(
            75.0,
            (float) DailyJournalEntry::query()
                ->where('project_id', $project->id)
                ->whereDate('journal_date', $journalDate)
                ->value('daily_income')
        );

        $this->expectException(QueryException::class);

        DailyJournalEntry::query()->create([
            'project_id' => $project->id,
            'journal_date' => $journalDate,
            'daily_income' => 1,
        ]);
    }

    public function test_calculations_are_scoped_to_journal_date(): void
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
            'entries' => [['project_id' => $project->id, 'daily_income' => 100]],
        ])->assertOk();

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $dayTwo,
            'entries' => [['project_id' => $project->id, 'daily_income' => 40]],
        ])->assertOk();

        $dayOneEntry = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', $dayOne)
            ->first();
        $dayTwoEntry = DailyJournalEntry::query()
            ->where('project_id', $project->id)
            ->whereDate('journal_date', $dayTwo)
            ->first();

        $this->assertSame(100.0, (float) $dayOneEntry->daily_total);
        $this->assertSame(100.0, (float) $dayOneEntry->fund_balance);
        $this->assertSame(40.0, (float) $dayTwoEntry->daily_total);
        $this->assertSame(140.0, (float) $dayTwoEntry->fund_balance);
    }

    public function test_rejects_invalid_journal_date_format(): void
    {
        $this->actAsFinanceUser();
        $project = $this->createActiveProject();

        $this->getJson('/api/v1/daily-journals?journal_date=28-07-2026')
            ->assertStatus(422);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => 'not-a-date',
            'entries' => [['project_id' => $project->id, 'daily_income' => 10]],
        ])->assertStatus(422);
    }
}
