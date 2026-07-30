<?php

namespace Tests\Unit\Project;

use Modules\Project\Actions\Project\CalculateOperationalSettingsAction;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Tests\TestCase;

class CalculateOperationalSettingsActionTest extends TestCase
{
    public function test_fixed_deduction_keeps_fixed_amount(): void
    {
        $action = new CalculateOperationalSettingsAction;

        $settings = $action->execute(ProjectData::fromArray([
            'name' => 'Fixed Project',
            'category_id' => 1,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 154,
            'administrative_exempt' => false,
        ]));

        $this->assertSame(OperationalDeductionType::Fixed, $settings['operational_deduction_type']);
        $this->assertSame(154.0, $settings['operational_fixed_amount']);
        $this->assertFalse($settings['administrative_exempt']);
    }

    public function test_relative_and_exempt_deductions_clear_fixed_amount(): void
    {
        $action = new CalculateOperationalSettingsAction;

        $relative = $action->execute(ProjectData::fromArray([
            'name' => 'Relative Project',
            'category_id' => 1,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'operational_fixed_amount' => 999,
            'administrative_exempt' => true,
        ]));

        $exempt = $action->execute(ProjectData::fromArray([
            'name' => 'Exempt Project',
            'category_id' => 1,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'operational_fixed_amount' => 999,
            'administrative_exempt' => false,
        ]));

        $this->assertSame(OperationalDeductionType::Relative, $relative['operational_deduction_type']);
        $this->assertNull($relative['operational_fixed_amount']);
        $this->assertTrue($relative['administrative_exempt']);

        $this->assertSame(OperationalDeductionType::Exempt, $exempt['operational_deduction_type']);
        $this->assertNull($exempt['operational_fixed_amount']);
        $this->assertFalse($exempt['administrative_exempt']);
    }
}
