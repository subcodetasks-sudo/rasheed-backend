<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Validation\ValidationException;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\DailyJournal\Services\DailyJournalCalculationService;
use Modules\Project\Models\Project;

class RepayAdministrativeDebtAction
{
    public function __construct(
        private readonly DailyJournalCalculationService $calculationService,
    ) {}

    public function execute(string $journalDate, int $projectId): DailyJournalEntry
    {
        $project = Project::query()->active()->whereKey($projectId)->first();

        if (! $project) {
            throw ValidationException::withMessages([
                'project_id' => [__('messages.repay_debt_project_not_found')],
            ]);
        }

        $entry = DailyJournalEntry::query()
            ->with(['project.category'])
            ->where('project_id', $projectId)
            ->whereDate('journal_date', $journalDate)
            ->first();

        if (! $entry) {
            throw ValidationException::withMessages([
                'project_id' => [__('messages.repay_debt_entry_not_found')],
            ]);
        }

        $fundBalance = (float) $entry->fund_balance;

        if ($fundBalance <= 0) {
            throw ValidationException::withMessages([
                'project_id' => [__('messages.repay_debt_requires_surplus')],
            ]);
        }

        $result = $this->calculationService->repayAdministrativeDebtFromSurplus(
            $fundBalance,
            (float) $entry->administrative_debt,
            (float) $entry->accumulated_administrative_debt,
        );

        $entry->fund_balance = $result['fund_balance'];
        $entry->administrative_debt = $result['administrative_debt'];
        $entry->accumulated_administrative_debt = $result['accumulated_administrative_debt'];
        $entry->updated_by = auth()->user()?->uuid;
        $entry->save();

        $entry = $entry->loadMissing(['project.category']);

        AdministrativeDebtRepaid::dispatch($entry);

        return $entry;
    }
}
