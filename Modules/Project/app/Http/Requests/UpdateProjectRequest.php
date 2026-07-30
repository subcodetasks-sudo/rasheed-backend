<?php

namespace Modules\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Rules\OperationalFixedAmountRule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('project')?->id ?? $this->route('project');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('projects', 'name')->ignore($projectId)],
            'category_id' => ['required', 'exists:categories,id'],
            'fund_type' => ['required', Rule::enum(FundType::class)],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'operational_deduction_type' => ['required', Rule::enum(OperationalDeductionType::class)],
            'operational_fixed_amount' => [
                'required_if:operational_deduction_type,fixed',
                'nullable',
                'numeric',
                'gt:0',
                'max:9999999999.99',
                new OperationalFixedAmountRule($this->input('operational_deduction_type')),
            ],
            'administrative_exempt' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'operational_fixed_amount.required' => __('The operational fixed amount is required when the deduction type is fixed.'),
            'operational_fixed_amount.required_if' => __('The operational fixed amount is required when the deduction type is fixed.'),
            'operational_fixed_amount.gt' => __('The operational fixed amount must be greater than zero.'),
        ];
    }
}
