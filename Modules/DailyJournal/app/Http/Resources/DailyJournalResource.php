<?php

namespace Modules\DailyJournal\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Actions\BuildDailyJournalDayTotalsAction;
use Modules\DailyJournal\Services\AdministrativePercentageBalanceService;
use Modules\Project\Actions\Project\ResolveEffectiveOperationalDeductionAction;

class DailyJournalResource extends JsonResource
{
    /**
     * @param  array{journal_date: string, entries: Collection}  $resource
     */
    public function toArray(Request $request): array
    {
        $journalDate = $this->resource['journal_date'];
        $adminBalanceService = app(AdministrativePercentageBalanceService::class);
        $operationalPoolResolver = app(ResolveEffectiveOperationalDeductionAction::class);
        $totals = app(BuildDailyJournalDayTotalsAction::class)
            ->execute(Carbon::parse($journalDate)->startOfDay());

        return [
            'journal_date' => $journalDate,
            'available_administrative_percentage_balance' => $adminBalanceService
                ->availableBalance(),
            // Effective operational-deduction pool for this journal date only (not an all-time total).
            'operational_deduction' => $operationalPoolResolver
                ->execute($journalDate),
            'totals' => $totals,
            'entries' => DailyJournalEntryResource::collection($this->resource['entries']),
        ];
    }
}
