<?php

namespace Modules\Dashboard\Http\Requests;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ShowDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function journalDate(): CarbonInterface
    {
        $date = $this->validated('journal_date');

        return $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
    }
}
