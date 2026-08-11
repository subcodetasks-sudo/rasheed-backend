<?php

namespace Modules\AdministrativeFund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAdministrativeFundDayRequest extends FormRequest
{
    private const EDITABLE = [
        'individual_contributions',
        'asset_administration',
        'operational_administration',
        'notes',
    ];

    private const FORBIDDEN = [
        'project_administration',
        'cash_fund_contributions',
        'debt_recovery',
        'total_income',
        'total_expenses',
        'administrative_net',
        'net',
        'date',
        'day',
        'month',
        'year',
        'summary',
        'days',
        'totals',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'individual_contributions' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'asset_administration' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'operational_administration' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $input = $this->all();
            foreach (array_keys($input) as $key) {
                if (in_array($key, self::FORBIDDEN, true) || ! in_array($key, self::EDITABLE, true)) {
                    $validator->errors()->add(
                        $key,
                        __('messages.administrative_fund_field_not_editable', ['field' => $key]),
                    );
                }
            }
        });
    }

    /**
     * @return array{individual_contributions?: float|null, asset_administration?: float|null, operational_administration?: float|null, notes?: string|null}
     */
    public function editablePayload(): array
    {
        $validated = $this->validated();
        $payload = [];

        if (array_key_exists('individual_contributions', $validated)) {
            $payload['individual_contributions'] = $validated['individual_contributions'] === null
                ? 0.0
                : (float) $validated['individual_contributions'];
        }

        if (array_key_exists('asset_administration', $validated)) {
            $payload['asset_administration'] = $validated['asset_administration'] === null
                ? 0.0
                : (float) $validated['asset_administration'];
        }

        if (array_key_exists('operational_administration', $validated)) {
            $payload['operational_administration'] = $validated['operational_administration'] === null
                ? 0.0
                : (float) $validated['operational_administration'];
        }

        if (array_key_exists('notes', $validated)) {
            $payload['notes'] = $validated['notes'];
        }

        return $payload;
    }
}
