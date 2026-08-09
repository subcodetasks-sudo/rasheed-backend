<?php

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            // Currency is exposed on GET but not writable for now.
            'currency' => ['prohibited'],
            'admin_fee_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (
                ! $this->exists('organization_name')
                && ! $this->exists('admin_fee_percentage')
            ) {
                $validator->errors()->add(
                    'organization_name',
                    __('validation.required_without_all', [
                        'attribute' => 'organization_name',
                        'values' => 'admin_fee_percentage',
                    ])
                );
            }
        });
    }

    /**
     * @return array{organization_name?: string, admin_fee_percentage?: float|int|string}
     */
    public function settings(): array
    {
        return $this->safe()->only([
            'organization_name',
            'admin_fee_percentage',
        ]);
    }
}
