<?php

namespace Modules\DailyJournal\ProjectDeletionConstraints;

use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Contracts\ProjectDeletionConstraint;
use Modules\Project\Models\Project;

class HasJournalEntriesConstraint implements ProjectDeletionConstraint
{
  /**
   * Block only when the project has journal history that affects administration:
   * administrative debt, accumulated debt, fund deficit (عجز), admin fee/expense, or contribution.
   * Zero placeholder rows created for every active project on a journal day do not block.
   */
  public function blocks(Project $project): ?string
  {
    $hasBlockingHistory = DailyJournalEntry::query()
      ->where('project_id', $project->id)
      ->where(function ($query) {
        $query->where('administrative_debt', '!=', 0)
          ->orWhere('accumulated_administrative_debt', '!=', 0)
          ->orWhere('fund_balance', '<', 0)
          ->orWhere('administrative_fee', '!=', 0)
          ->orWhere('administrative_expense', '!=', 0)
          ->orWhere('contribution', '!=', 0);
      })
      ->exists();

        if ($hasBlockingHistory) {
            return __('messages.project_has_journal_entries');
        }

    return null;
  }
}
