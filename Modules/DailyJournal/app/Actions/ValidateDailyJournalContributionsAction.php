<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Validation\ValidationException;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\DTOs\DailyJournalEntryInput;
use Modules\DailyJournal\Services\AdministrativePercentageBalanceService;
use Modules\User\app\Models\User;

class ValidateDailyJournalContributionsAction
{
    public function __construct(
        private readonly AdministrativePercentageBalanceService $administrativePercentageBalanceService,
    ) {}

    /**
     * @param  array<int, float>  $remainingDeficits  project_id => remaining deficit after Pass 1
     * @param  array<int, float>  $existingContributions  project_id => stored contribution
     * @param  array<int, int|null>  $entryIdsByProject  project_id => journal entry id
     */
    public function execute(
        DailyJournalData $data,
        array $remainingDeficits,
        ?User $user,
        bool $replaceMissingEditableFields,
        array $existingContributions,
        array $entryIdsByProject,
    ): void {
        $errors = [];
        $poolRemaining = $this->administrativePercentageBalanceService->availableBalance();

        foreach ($data->entries as $index => $input) {
            $previous = round((float) ($existingContributions[$input->projectId] ?? 0), 2);
            $incoming = $this->resolveIncomingContribution($input, $replaceMissingEditableFields, $previous);
            $incomingAmount = round((float) ($incoming ?? 0), 2);

            if (round($incomingAmount, 2) === round($previous, 2)) {
                continue;
            }

            if (! $user || ! $user->hasRole('super-admin')) {
                $errors["entries.{$index}.contribution"] = [__('messages.contribution_requires_super_admin')];

                continue;
            }

            if ($incomingAmount <= 0) {
                // Clear / zero: super-admin only (above). Non-refundable debits are left intact.
                continue;
            }

            $remainingDeficit = (float) ($remainingDeficits[$input->projectId] ?? 0);

            if ($remainingDeficit <= 0) {
                $errors["entries.{$index}.contribution"] = [__('messages.contribution_requires_deficit')];

                continue;
            }

            $consumed = $this->administrativePercentageBalanceService->consumedForEntry(
                $entryIdsByProject[$input->projectId] ?? null
            );
            $additionalNeeded = round(max(0, $incomingAmount - $consumed), 2);
            $maxAllowed = round(min($remainingDeficit, $consumed + $poolRemaining), 2);

            if ($incomingAmount > $remainingDeficit) {
                $errors["entries.{$index}.contribution"] = [__('messages.contribution_exceeds_remaining_deficit')];

                continue;
            }

            if ($additionalNeeded > $poolRemaining || $incomingAmount > $maxAllowed) {
                $errors["entries.{$index}.contribution"] = [__('messages.contribution_exceeds_admin_percentage_balance')];

                continue;
            }

            $poolRemaining = round($poolRemaining - $additionalNeeded, 2);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolveIncomingContribution(
        DailyJournalEntryInput $input,
        bool $replaceMissingEditableFields,
        float $previous,
    ): ?float {
        if ($input->hasContribution) {
            return $input->contribution;
        }

        if ($replaceMissingEditableFields) {
            return null;
        }

        return $previous;
    }
}
