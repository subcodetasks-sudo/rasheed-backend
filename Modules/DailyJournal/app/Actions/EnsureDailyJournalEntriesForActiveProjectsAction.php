<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Project\Models\Project;

class EnsureDailyJournalEntriesForActiveProjectsAction
{
    public function execute(CarbonInterface $date): Collection
    {
        $userId = auth()->user()?->uuid;
        $dateString = $date->toDateString();

        $projects = Project::query()->active()->with('category')->get();
        $existing = DailyJournalEntry::query()
            ->whereDate('journal_date', $dateString)
            ->whereIn('project_id', $projects->pluck('id'))
            ->get()
            ->keyBy('project_id');

        foreach ($projects as $project) {
            if ($existing->has($project->id)) {
                continue;
            }

            $entry = DailyJournalEntry::query()->create([
                'journal_date' => $dateString,
                'project_id' => $project->id,
                'daily_income' => null,
                'daily_expense' => null,
                'contribution' => null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $existing->put($project->id, $entry);
        }

        return DailyJournalEntry::query()
            ->with(['project.category'])
            ->whereDate('journal_date', $dateString)
            ->whereIn('project_id', $projects->pluck('id'))
            ->get();
    }
}
