<?php

namespace Modules\DailyJournal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'administrative_expense' => $this->formatDecimal($this->administrative_expense),
            'administrative_fee' => $this->formatDecimal($this->administrative_fee),
            'operational_deduction' => $this->formatDecimal($this->operational_deduction),
            'daily_total' => $this->formatDecimal($this->daily_total),
            'fund_balance' => $this->formatDecimal($this->fund_balance),
            'administrative_debt' => $this->formatDecimal($this->administrative_debt),
            'accumulated_administrative_debt' => $this->formatDecimal($this->accumulated_administrative_debt),
        ];
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->formatDecimal($value);
    }

    private function formatDecimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
