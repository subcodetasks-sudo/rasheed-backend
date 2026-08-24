<?php

namespace Modules\DailyJournal\Http\Resources;

use App\Support\Money\FormatMoneyDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DailyJournal\Support\FundBalanceStatus;

class DailyJournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // 'journal_date' => $this->journal_date?->toDateString(),
            'project' => [
                'id' => $this->project?->id,
                'name' => $this->project?->name,
                'category' => [
                    'id' => $this->project?->category?->id,
                    'name' => $this->project?->category?->name,
                ],
                'administrative_exempt' => (bool) $this->project?->administrative_exempt,
            ],
            'daily_income' => $this->nullableDecimal($this->daily_income),
            'daily_expense' => $this->nullableDecimal($this->daily_expense),
            'contribution' => $this->nullableDecimal($this->contribution),
            'administrative_expense' => FormatMoneyDecimal::formatRounded($this->administrative_expense),
            'uncovered_administrative_expense' => FormatMoneyDecimal::formatRounded($this->uncovered_administrative_expense),
            'project_administration_settled' => FormatMoneyDecimal::formatRounded($this->project_administration_settled),
            'outstanding_project_administration' => FormatMoneyDecimal::formatRounded($this->outstanding_project_administration),
            'administrative_fee' => FormatMoneyDecimal::formatRounded($this->administrative_fee),
            'operational_deduction' => FormatMoneyDecimal::formatRounded($this->operational_deduction),
            'daily_total' => FormatMoneyDecimal::formatRounded($this->daily_total),
            'fund_balance' => FormatMoneyDecimal::formatRounded($this->fund_balance),
            'administrative_debt' => FormatMoneyDecimal::formatRounded($this->administrative_debt),
            'accumulated_administrative_debt' => FormatMoneyDecimal::formatRounded($this->accumulated_administrative_debt),
            'administrative_pool_relief' => FormatMoneyDecimal::formatRounded($this->administrative_pool_relief),
            'administrative_pool_relief_given' => FormatMoneyDecimal::formatRounded($this->administrative_pool_relief_given),
            'administrative_fee_remaining' => FormatMoneyDecimal::formatRounded($this->administrative_fee_remaining),
            'status' => FundBalanceStatus::from((float) ($this->fund_balance ?? 0)),
        ];
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return FormatMoneyDecimal::format($value);
    }
}
