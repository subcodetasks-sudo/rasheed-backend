<?php

namespace Modules\OperationalFund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOperationalFundDayRequest extends FormRequest
{
    private const EDITABLE = ['operational_expense'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operational_expense' => ['present', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, self::EDITABLE, true)) {
                    $validator->errors()->add(
                        $key,
                        __('messages.operational_fund_field_not_editable', ['field' => $key]),
                    );
                }
            }
        });
    }

    public function operationalExpense(): float
    {
        $value = $this->validated('operational_expense');

        return $value === null ? 0.0 : (float) $value;
    }
}
