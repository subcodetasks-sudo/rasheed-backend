<?php

namespace Modules\DailyJournal\Actions;

use Illuminate\Validation\ValidationException;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\User\app\Models\User;

class ValidateDailyJournalContributionsAction
{
    /**
     * Validate positive contributions against Pass 1 remaining deficits.
     *
     * Null or zero contributions bypass all checks (super-admin, deficit, caps).
     *
     * Remaining Deficit = abs(negative signed fund_balance) from Pass 1.
     *
     * @param  array<int, float>  $remainingDeficits  project_id => remaining deficit
     */
    public function execute(DailyJournalData $data, array $remainingDeficits, ?User $user): void
    {
        $errors = [];

        foreach ($data->entries as $index => $input) {
            if (! $input->hasContribution || $input->contribution === null) {
                continue;
            }

            $amount = (float) $input->contribution;

            if ($amount <= 0) {
                continue;
            }

            if (! $user || ! $user->hasRole('super-admin')) {
                $errors["entries.{$index}.contribution"] = [__('messages.contribution_requires_super_admin')];

                continue;
            }

            $remainingDeficit = (float) ($remainingDeficits[$input->projectId] ?? 0);

            if ($remainingDeficit <= 0) {
                $errors["entries.{$index}.contribution"] = [__('messages.contribution_requires_deficit')];

                continue;
            }

            if ($amount > $remainingDeficit) {
                $errors["entries.{$index}.contribution"] = [__('messages.contribution_exceeds_remaining_deficit')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
