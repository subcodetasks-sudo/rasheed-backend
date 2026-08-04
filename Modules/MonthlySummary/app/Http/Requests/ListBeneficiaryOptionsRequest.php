<?php

namespace Modules\MonthlySummary\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MonthlySummary\Enums\ContributionType;

class ListBeneficiaryOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'from_project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'contribution_type' => ['required', Rule::enum(ContributionType::class)],
        ];
    }

    public function month(): int
    {
        return (int) $this->validated('month');
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function fromProjectId(): int
    {
        return (int) $this->validated('from_project_id');
    }

    public function contributionType(): ContributionType
    {
        return ContributionType::from($this->validated('contribution_type'));
    }
}
