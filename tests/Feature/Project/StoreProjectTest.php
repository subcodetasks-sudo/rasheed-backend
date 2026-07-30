<?php

namespace Tests\Feature\Project;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;

class StoreProjectTest extends ProjectFeatureTestCase
{
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsSuperAdmin(['create-projects']);
        $this->category = $this->createCategory(['name' => 'Relief']);
    }

    public function test_can_create_fixed_relative_and_exempt_projects(): void
    {
        $fixedResponse = $this->postJson('/api/v1/projects', [
            'name' => 'Fixed Project',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => 154.75,
            'administrative_exempt' => false,
        ]);

        $fixedResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Fixed Project')
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.fund_type', FundType::Fixed->value)
            ->assertJsonPath('data.status', ProjectStatus::Active->value)
            ->assertJsonPath('data.operational_deduction_type', OperationalDeductionType::Fixed->value)
            ->assertJsonPath('data.operational_fixed_amount', '154.75')
            ->assertJsonPath('data.administrative_exempt', false);

        $relativeResponse = $this->postJson('/api/v1/projects', [
            'name' => 'Relative Project',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => true,
        ]);

        $relativeResponse->assertCreated()
            ->assertJsonPath('data.operational_deduction_type', OperationalDeductionType::Relative->value)
            ->assertJsonMissingPath('data.operational_fixed_amount')
            ->assertJsonPath('data.administrative_exempt', true);

        $exemptResponse = $this->postJson('/api/v1/projects', [
            'name' => 'Exempt Project',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => false,
        ]);

        $exemptResponse->assertCreated()
            ->assertJsonPath('data.operational_deduction_type', OperationalDeductionType::Exempt->value)
            ->assertJsonMissingPath('data.operational_fixed_amount');

        $this->assertDatabaseHas('projects', [
            'name' => 'Fixed Project',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'operational_fixed_amount' => '154.75',
            'administrative_exempt' => 0,
        ]);

        $this->assertDatabaseHas('projects', [
            'name' => 'Relative Project',
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'operational_fixed_amount' => null,
            'administrative_exempt' => 1,
        ]);

        $this->assertDatabaseHas('projects', [
            'name' => 'Exempt Project',
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'operational_fixed_amount' => null,
        ]);
    }

    public function test_administrative_exempt_defaults_to_false_when_omitted(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Implicit Administrative Rule',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.administrative_exempt', false);

        $this->assertDatabaseHas('projects', [
            'name' => 'Implicit Administrative Rule',
            'administrative_exempt' => 0,
        ]);
    }

    public function test_creation_accepts_arabic_english_and_unicode_names(): void
    {
        $this->postJson('/api/v1/projects', [
            'name' => 'مشروع الإغاثة',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $this->postJson('/api/v1/projects', [
            'name' => 'Community Support',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/projects', [
            'name' => 'Project №1',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertCreated();

        $this->assertDatabaseCount('projects', 3);
    }

    public function test_creation_validates_required_fields_and_invalid_enums(): void
    {
        $response = $this->postJson('/api/v1/projects', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'category_id',
                'fund_type',
                'status',
                'operational_deduction_type',
            ]);

        $this->postJson('/api/v1/projects', [
            'name' => 'Invalid Enums',
            'category_id' => $this->category->id,
            'fund_type' => 'bad-fund',
            'status' => 'bad-status',
            'operational_deduction_type' => 'bad-deduction',
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['fund_type', 'status', 'operational_deduction_type']);
    }

    public function test_creation_validates_name_category_and_uniqueness_rules(): void
    {
        Project::factory()->create([
            'name' => 'Unique Name',
            'category_id' => $this->category->id,
        ]);

        $this->postJson('/api/v1/projects', [
            'name' => str_repeat('A', 256),
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Unique Name',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Missing Category',
            'category_id' => 999999,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_fixed_amount_is_required_only_for_fixed_deduction_and_rejected_otherwise(): void
    {
        $this->postJson('/api/v1/projects', [
            'name' => 'Fixed Missing Amount',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Relative With Amount',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Relative->value,
            'operational_fixed_amount' => 25,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Exempt With Amount',
            'category_id' => $this->category->id,
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'operational_fixed_amount' => 25,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['operational_fixed_amount']);
    }

    public function test_fixed_amount_validates_numeric_boundaries_and_boolean_values(): void
    {
        $payload = [
            'category_id' => $this->category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Fixed->value,
        ];

        $this->postJson('/api/v1/projects', $payload + [
            'name' => 'Negative Amount',
            'operational_fixed_amount' => -1,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', $payload + [
            'name' => 'Zero Amount',
            'operational_fixed_amount' => 0,
            'administrative_exempt' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['operational_fixed_amount']);

        $this->postJson('/api/v1/projects', $payload + [
            'name' => 'Decimal Amount',
            'operational_fixed_amount' => 12.34,
            'administrative_exempt' => true,
        ])->assertCreated()
            ->assertJsonPath('data.operational_fixed_amount', '12.34');

        $this->postJson('/api/v1/projects', $payload + [
            'name' => 'Large Amount',
            'operational_fixed_amount' => 9999999999.99,
            'administrative_exempt' => false,
        ])->assertCreated();

        $this->postJson('/api/v1/projects', $payload + [
            'name' => 'Invalid Boolean',
            'operational_fixed_amount' => 50,
            'administrative_exempt' => 'not-bool',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['administrative_exempt']);
    }
}
