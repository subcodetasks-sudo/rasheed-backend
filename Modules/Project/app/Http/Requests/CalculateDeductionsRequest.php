<?php

namespace Modules\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateDeductionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'incomes' => ['required', 'array', 'min:1'],
            'incomes.*' => ['required', 'numeric', 'min:0'],
        ];
    }
}
