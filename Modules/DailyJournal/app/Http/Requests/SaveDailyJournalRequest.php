<?php

namespace Modules\DailyJournal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;

class SaveDailyJournalRequest extends FormRequest
{
    private const CALCULATED_FIELDS = [
        'administrative_expense',
        'uncovered_administrative_expense',
        'administrative_fee',
        'operational_deduction',
        'daily_total',
        'fund_balance',
        'accumulated_administrative_debt',
        'administrative_debt',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.project_id' => [
                'required',
                'integer',
                'distinct',
                'exists:projects,id',
            ],
            'entries.*.daily_income' => ['nullable', 'numeric', 'min:0'],
            'entries.*.daily_expense' => ['nullable', 'numeric', 'min:0'],
            'entries.*.contribution' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $entries = $this->input('entries', []);

            if (! is_array($entries)) {
                return;
            }

            foreach ($entries as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                foreach (self::CALCULATED_FIELDS as $field) {
                    if (array_key_exists($field, $entry)) {
                        $validator->errors()->add(
                            "entries.{$index}.{$field}",
                            __('messages.calculated_field_not_allowed', ['field' => $field])
                        );
                    }
                }

                if (! isset($entry['project_id'])) {
                    continue;
                }

                $project = Project::query()->find($entry['project_id']);
                if ($project && $project->status !== ProjectStatus::Active) {
                    $validator->errors()->add(
                        "entries.{$index}.project_id",
                        __('messages.only_active_projects_allowed_in_journal')
                    );
                }
            }
        });
    }
}
