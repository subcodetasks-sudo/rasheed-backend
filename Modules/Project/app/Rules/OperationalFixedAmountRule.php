<?php

namespace Modules\Project\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Project\Enums\OperationalDeductionType;

class OperationalFixedAmountRule implements ValidationRule
{
    public function __construct(
        private readonly ?string $deductionType,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->deductionType === OperationalDeductionType::Fixed->value) {
            if ($value === null || $value === '') {
                $fail(__('validation.required', ['attribute' => $attribute]));

                return;
            }

            if (! is_numeric($value) || (float) $value <= 0) {
                $fail(__('The operational fixed amount must be greater than zero.'));
            }

            return;
        }

        if ($value !== null && $value !== '') {
            $fail(__('The operational fixed amount must be empty unless the deduction type is fixed.'));
        }
    }
}
