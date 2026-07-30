<?php

namespace Modules\DailyJournal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepayAdministrativeDebtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date_format:Y-m-d'],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
        ];
    }

    public function journalDate(): string
    {
        return $this->validated('journal_date');
    }

    public function projectId(): int
    {
        return (int) $this->validated('project_id');
    }
}
