<?php

namespace Tests\Unit\Project;

use Illuminate\Support\Facades\Validator;
use Modules\Project\Rules\OperationalFixedAmountRule;
use Tests\TestCase;

class OperationalFixedAmountRuleTest extends TestCase
{
    public function test_fixed_deduction_requires_a_value(): void
    {
        $validator = Validator::make(
            ['operational_fixed_amount' => null],
            ['operational_fixed_amount' => [new OperationalFixedAmountRule('fixed')]]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('operational_fixed_amount', $validator->errors()->toArray());
    }

    public function test_non_fixed_deduction_rejects_any_provided_value(): void
    {
        $validator = Validator::make(
            ['operational_fixed_amount' => 10],
            ['operational_fixed_amount' => [new OperationalFixedAmountRule('relative')]]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('operational_fixed_amount', $validator->errors()->toArray());
    }

    public function test_valid_combinations_pass_the_rule(): void
    {
        $fixedValidator = Validator::make(
            ['operational_fixed_amount' => 10.5],
            ['operational_fixed_amount' => [new OperationalFixedAmountRule('fixed')]]
        );

        $relativeValidator = Validator::make(
            ['operational_fixed_amount' => null],
            ['operational_fixed_amount' => [new OperationalFixedAmountRule('relative')]]
        );

        $this->assertFalse($fixedValidator->fails());
        $this->assertFalse($relativeValidator->fails());
    }

    public function test_fixed_deduction_rejects_zero_and_negative_values(): void
    {
        $zeroValidator = Validator::make(
            ['operational_fixed_amount' => 0],
            ['operational_fixed_amount' => [new OperationalFixedAmountRule('fixed')]]
        );

        $negativeValidator = Validator::make(
            ['operational_fixed_amount' => -5],
            ['operational_fixed_amount' => [new OperationalFixedAmountRule('fixed')]]
        );

        $this->assertTrue($zeroValidator->fails());
        $this->assertTrue($negativeValidator->fails());
    }

    public function test_exempt_deduction_rejects_any_provided_value(): void
    {
        $validator = Validator::make(
            ['operational_fixed_amount' => 10],
            ['operational_fixed_amount' => [new OperationalFixedAmountRule('exempt')]]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('operational_fixed_amount', $validator->errors()->toArray());
    }
}
