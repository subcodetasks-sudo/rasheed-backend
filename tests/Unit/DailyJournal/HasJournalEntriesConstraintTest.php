<?php

namespace Tests\Unit\DailyJournal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\ProjectDeletionConstraints\HasJournalEntriesConstraint;
use Modules\Project\Models\Project;
use Tests\TestCase;

class HasJournalEntriesConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_placeholder_journal_rows_do_not_block_deletion(): void
    {
        $project = Project::factory()->create();
        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
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
        ]);

        $this->assertNull((new HasJournalEntriesConstraint)->blocks($project));
    }

    public function test_blocks_when_administrative_debt_exists(): void
    {
        $project = Project::factory()->create();
        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'administrative_debt' => 10,
        ]);

        $this->assertSame(
            __('messages.project_has_journal_financial_history'),
            (new HasJournalEntriesConstraint)->blocks($project)
        );
    }

    public function test_blocks_when_fund_is_in_deficit(): void
    {
        $project = Project::factory()->create();
        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'fund_balance' => -5,
        ]);

        $this->assertSame(
            __('messages.project_has_journal_financial_history'),
            (new HasJournalEntriesConstraint)->blocks($project)
        );
    }

    public function test_blocks_when_admin_fee_or_expense_exists(): void
    {
        $project = Project::factory()->create();
        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'administrative_fee' => 12,
        ]);

        $this->assertSame(
            __('messages.project_has_journal_financial_history'),
            (new HasJournalEntriesConstraint)->blocks($project)
        );
    }
}
