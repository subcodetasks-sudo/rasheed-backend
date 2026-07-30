<?php

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $key = $this->route('key');

        $rules = [
            'value' => ['required'],
            'type' => ['nullable', Rule::in(['string', 'integer', 'boolean', 'json', 'decimal'])],
            'is_public' => ['nullable', 'boolean'],
        ];

        if ($key === 'admin_fee_percentage') {
            $rules['value'] = ['required', 'numeric', 'min:0', 'max:100'];
            $rules['type'] = ['nullable', Rule::in(['decimal'])];
        }

        if ($key === 'total_operational_deduction') {
            $rules['value'] = ['required', 'numeric', 'gt:0', 'max:9999999999.99'];
            $rules['type'] = ['nullable', Rule::in(['decimal'])];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $key = $this->route('key');

        if (in_array($key, ['admin_fee_percentage', 'total_operational_deduction'], true) && ! $this->filled('type')) {
            $this->merge(['type' => 'decimal']);
        }
    }
}
