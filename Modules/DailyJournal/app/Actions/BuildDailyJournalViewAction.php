<?php

namespace Modules\DailyJournal\Actions;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\DailyJournalCalculationService;
use Modules\Project\Models\Project;

class BuildDailyJournalViewAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    /**
     * Build a paginated journal view for active projects without persisting missing ones.
     *
     * @return LengthAwarePaginator<int, DailyJournalEntry>
     */
    public function execute(CarbonInterface $date, int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $dateString = $date->toDateString();

        $projectsPage = Project::query()
            ->active()
            ->with('category')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        /** @var Collection<int, Project> $projects */
        $projects = $projectsPage->getCollection();

        $stored = DailyJournalEntry::query()
            ->with(['project.category'])
            ->whereDate('journal_date', $dateString)
            ->whereIn('project_id', $projects->pluck('id'))
            ->get()
            ->keyBy('project_id');

        $previous = $this->calculationService->previousBalances(
            $projects->pluck('id')->all(),
            $date
        );

        $entries = $projects->map(function (Project $project) use ($stored, $previous, $dateString) {
            if ($stored->has($project->id)) {
                $entry = $stored->get($project->id);
                $entry->setRelation('project', $project);

                return $entry;
            }

            $entry = new DailyJournalEntry([
                'journal_date' => $dateString,
                'project_id' => $project->id,
                'daily_income' => null,
                'daily_expense' => null,
                'contribution' => null,
                'administrative_expense' => 0,
                'administrative_fee' => 0,
                'operational_deduction' => 0,
                'daily_total' => 0,
                'fund_balance' => $previous[$project->id]['fund_balance'] ?? 0,
                'administrative_debt' => 0,
                'accumulated_administrative_debt' => $previous[$project->id]['accumulated_administrative_debt'] ?? 0,
            ]);
            $entry->setRelation('project', $project);

            return $entry;
        })->values();

        return new Paginator(
            $entries,
            $projectsPage->total(),
            $projectsPage->perPage(),
            $projectsPage->currentPage(),
            [
                'path' => $projectsPage->path(),
                'pageName' => $projectsPage->getPageName(),
                'query' => request()->query(),
            ]
        );
    }
}
