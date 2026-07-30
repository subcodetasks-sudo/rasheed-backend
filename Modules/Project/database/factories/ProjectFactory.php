<?php

namespace Modules\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'category_id' => Category::factory(),
            'fund_type' => fake()->randomElement(FundType::cases()),
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'operational_fixed_amount' => null,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
            'archived_at' => null,
        ];
    }

    public function fixedFund(): static
    {
        return $this->state(fn () => ['fund_type' => FundType::Fixed]);
    }

    public function variableFund(): static
    {
        return $this->state(fn () => ['fund_type' => FundType::Variable]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => ProjectStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    public function fixedDeduction(float $amount = 154): static
    {
        return $this->state(fn () => [
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => $amount,
        ]);
    }

    public function exempt(): static
    {
        return $this->state(fn () => [
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'operational_fixed_amount' => null,
        ]);
    }
}
