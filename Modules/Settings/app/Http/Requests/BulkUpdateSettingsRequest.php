<?php

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkUpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['required'],
            'settings.*.type' => ['nullable', Rule::in(['string', 'integer', 'boolean', 'json', 'decimal'])],
            'settings.*.is_public' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('settings', []) as $index => $item) {
                $key = $item['key'] ?? null;
                $value = $item['value'] ?? null;

                if ($key === 'admin_fee_percentage') {
                    if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                        $validator->errors()->add(
                            "settings.{$index}.value",
                            __('validation.between.numeric', ['attribute' => 'value', 'min' => 0, 'max' => 100])
                        );
                    }
                }

                if ($key === 'total_operational_deduction') {
                    if (! is_numeric($value) || (float) $value <= 0) {
                        $validator->errors()->add(
                            "settings.{$index}.value",
                            __('validation.gt.numeric', ['attribute' => 'value', 'value' => 0])
                        );
                    }
                }
            }
        });
    }
}
