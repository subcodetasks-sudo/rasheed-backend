<?php

namespace Modules\AdministrativeFund\Actions;

use Modules\AdministrativeFund\Models\AdministrativeFundDay;

class UpsertAdministrativeFundDayAction
{
    /**
     * @param  array{individual_contributions?: float|null, asset_administration?: float|null, operational_administration?: float|null, notes?: string|null}  $data
     */
    public function execute(string $date, array $data, ?string $updatedBy = null): AdministrativeFundDay
    {
        $day = AdministrativeFundDay::query()->whereDate('date', $date)->first()
            ?? new AdministrativeFundDay(['date' => $date]);

        if (array_key_exists('individual_contributions', $data)) {
            $day->individual_contributions = round((float) ($data['individual_contributions'] ?? 0), 2);
        } elseif (! $day->exists) {
            $day->individual_contributions = 0;
        }

        if (array_key_exists('asset_administration', $data)) {
            $day->asset_administration = round((float) ($data['asset_administration'] ?? 0), 2);
        } elseif (! $day->exists) {
            $day->asset_administration = 0;
        }

        if (array_key_exists('operational_administration', $data)) {
            $day->operational_administration = round((float) ($data['operational_administration'] ?? 0), 2);
        } elseif (! $day->exists) {
            $day->operational_administration = 0;
        }

        if (array_key_exists('notes', $data)) {
            $day->notes = $data['notes'];
        }

        $day->updated_by = $updatedBy;
        $day->save();

        return $day;
    }
}
