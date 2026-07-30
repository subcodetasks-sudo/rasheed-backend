<?php

namespace Modules\DailyJournal\Http\Requests;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ShowDailyJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function journalDate(): CarbonInterface
    {
        $date = $this->validated('journal_date');

        return $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    public function page(): ?int
    {
        $page = $this->validated('page');

        return $page !== null ? (int) $page : null;
    }
}
