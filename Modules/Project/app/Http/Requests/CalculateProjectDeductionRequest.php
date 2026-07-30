<?php

namespace Modules\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateProjectDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'income' => ['required', 'numeric', 'min:0'],
            'relative_incomes' => ['sometimes', 'array'],
            'relative_incomes.*' => ['numeric', 'min:0'],
        ];
    }
}
