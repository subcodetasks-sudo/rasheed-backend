<?php

namespace Modules\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\ProjectStatus;

class FilterProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable'],
            'tab' => ['sometimes', 'nullable', Rule::in(['fixed', 'variable', 'archived'])],
            'status' => ['sometimes', 'nullable', Rule::enum(ProjectStatus::class)],
            'fund_type' => ['sometimes', 'nullable', Rule::enum(FundType::class)],
            'category_id' => ['sometimes', 'nullable', 'exists:categories,id'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array'],
        ];
    }
}
