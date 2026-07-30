<?php

namespace Modules\DailyJournal\ProjectDeletionConstraints;

use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Contracts\ProjectDeletionConstraint;
use Modules\Project\Models\Project;

class HasJournalEntriesConstraint implements ProjectDeletionConstraint
{
    public function blocks(Project $project): ?string
    {
        if (DailyJournalEntry::query()->where('project_id', $project->id)->exists()) {
            return __('messages.project_has_journal_entries');
        }

        return null;
    }
}
