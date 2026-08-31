<?php

namespace Modules\OperationalFund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOperationalFundDayRequest extends FormRequest
{
    private const EDITABLE = ['expenses'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expenses' => ['present', 'nullable', 'array'],
            'expenses.*.amount' => ['required', 'numeric', 'gt:0'],
            'expenses.*.description' => ['required', 'string', 'max:255'],
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

    /**
     * Full-replace list of expense line items for the day.
     *
     * @return list<array{amount: float, description: string}>
     */
    public function expenses(): array
    {
        $rows = $this->validated('expenses') ?? [];

        return array_values(array_map(static fn (array $row): array => [
            'amount' => round((float) $row['amount'], 2),
            'description' => trim((string) $row['description']),
        ], $rows));
    }
}
