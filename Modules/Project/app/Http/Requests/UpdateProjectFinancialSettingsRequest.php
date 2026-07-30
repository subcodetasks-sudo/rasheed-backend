<?php

namespace Modules\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectFinancialSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_fee_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'total_operational_deduction' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999.99'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->exists('admin_fee_percentage') && ! $this->exists('total_operational_deduction')) {
                $validator->errors()->add(
                    'admin_fee_percentage',
                    __('validation.required_without', [
                        'attribute' => 'admin_fee_percentage',
                        'values' => 'total_operational_deduction',
                    ])
                );
            }
        });
    }
}
