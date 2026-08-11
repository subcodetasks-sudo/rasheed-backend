<?php

namespace Modules\Project\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AdministrativeFeePercentageRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            $fail(__('The administrative fee percentage must be between 0 and 100.'));
        }
    }
}
