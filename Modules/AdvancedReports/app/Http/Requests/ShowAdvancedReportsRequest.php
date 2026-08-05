<?php

namespace Modules\AdvancedReports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowAdvancedReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', 'string', Rule::in(['month_comparison', 'inventory'])],
            'period' => ['required', 'integer', Rule::in([3, 6, 12])],
        ];
    }

    public function reportType(): string
    {
        return (string) $this->validated('report_type');
    }

    public function period(): int
    {
        return (int) $this->validated('period');
    }
}
