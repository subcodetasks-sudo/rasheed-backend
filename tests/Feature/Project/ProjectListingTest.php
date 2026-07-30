<?php

namespace Tests\Feature\Project;

use Carbon\Carbon;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Project;

class ProjectListingTest extends ProjectFeatureTestCase
{
    // Category text search is not implemented in production yet; current support is name search plus filter[category_id].

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsFinanceUser();
    }

    public function test_listing_returns_paginated_project_resources(): void
    {
        $category = $this->createCategory(['name' => 'Emergency']);

        Project::factory()->count(18)->create([
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed,
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Fixed,
            'operational_fixed_amount' => 125,
            'administrative_exempt' => true,
        ]);

        $response = $this->getJson('/api/v1/projects?per_page=15');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 18)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [[
                    'id',
                    'name',
                    'category' => ['id', 'name'],
                    'fund_type',
                    'status',
                    'operational_deduction_type',
                    'operational_fixed_amount',
                    'administrative_exempt',
                    'archived_at',
                    'created_by',
                    'updated_by',
                    'created_at',
                    'updated_at',
                ]],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
                'links' => ['next'],
            ]);

        $this->assertCount(15, $response->json('data'));
    }

    public function test_fixed_tab_only_returns_non_archived_fixed_projects(): void
    {
        $category = $this->createCategory();

        Project::factory()->fixedFund()->create(['name' => 'Fixed Active', 'category_id' => $category->id]);
        Project::factory()->variableFund()->create(['name' => 'Variable Active', 'category_id' => $category->id]);
        Project::factory()->fixedFund()->archived()->create(['name' => 'Fixed Archived', 'category_id' => $category->id]);

        $response = $this->getJson('/api/v1/projects?tab=fixed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fixed Active');
    }

    public function test_variable_tab_only_returns_non_archived_variable_projects(): void
    {
        $category = $this->createCategory();

        Project::factory()->variableFund()->create(['name' => 'Variable Active', 'category_id' => $category->id]);
        Project::factory()->fixedFund()->create(['name' => 'Fixed Active', 'category_id' => $category->id]);
        Project::factory()->variableFund()->archived()->create(['name' => 'Variable Archived', 'category_id' => $category->id]);

        $response = $this->getJson('/api/v1/projects?tab=variable');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Variable Active');
    }

    public function test_archived_tab_only_returns_archived_projects(): void
    {
        $category = $this->createCategory();

        Project::factory()->archived()->create(['name' => 'Archived Project', 'category_id' => $category->id]);
        Project::factory()->create(['name' => 'Active Project', 'category_id' => $category->id]);

        $response = $this->getJson('/api/v1/projects?tab=archived');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Archived Project')
            ->assertJsonPath('data.0.status', ProjectStatus::Archived->value);
    }

    public function test_listing_supports_partial_exact_case_insensitive_and_arabic_search_by_name(): void
    {
        $category = $this->createCategory(['name' => 'خدمات']);

        Project::factory()->create(['name' => 'Alpha Care', 'category_id' => $category->id]);
        Project::factory()->create(['name' => 'مشروع الإغاثة', 'category_id' => $category->id]);
        Project::factory()->create(['name' => 'Beta Fund', 'category_id' => $category->id]);

        $this->getJson('/api/v1/projects?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Care');

        $this->getJson('/api/v1/projects?search=Alpha Care')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Care');

        $this->getJson('/api/v1/projects?search=alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Care');

        $this->getJson('/api/v1/projects?search=الإغاثة')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مشروع الإغاثة');
    }

    public function test_empty_and_non_matching_search_behave_as_current_query_implementation(): void
    {
        $category = $this->createCategory(['name' => 'Education']);

        Project::factory()->create(['name' => 'Alpha Care', 'category_id' => $category->id]);
        Project::factory()->create(['name' => 'Beta Fund', 'category_id' => $category->id]);

        $this->getJson('/api/v1/projects?search=')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/projects?search=NoMatch')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_is_currently_supported_for_project_name_not_category_text(): void
    {
        $matchingCategory = $this->createCategory(['name' => 'Education']);
        $otherCategory = $this->createCategory(['name' => 'Health']);

        Project::factory()->create(['name' => 'Alpha Care', 'category_id' => $matchingCategory->id]);
        Project::factory()->create(['name' => 'Education Support', 'category_id' => $otherCategory->id]);

        $response = $this->getJson('/api/v1/projects?search=Education');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Education Support');
    }

    public function test_listing_supports_filters_date_ranges_and_combined_filters(): void
    {
        $category = $this->createCategory(['name' => 'Relief']);
        $otherCategory = $this->createCategory(['name' => 'Health']);

        $matching = Project::factory()->fixedFund()->create([
            'name' => 'Alpha Fixed',
            'category_id' => $category->id,
            'status' => ProjectStatus::Active,
            'created_at' => Carbon::parse('2026-07-10 09:00:00'),
        ]);

        Project::factory()->fixedFund()->create([
            'name' => 'Beta Fixed',
            'category_id' => $otherCategory->id,
            'status' => ProjectStatus::Active,
            'created_at' => Carbon::parse('2026-07-11 09:00:00'),
        ]);

        Project::factory()->variableFund()->create([
            'name' => 'Alpha Variable',
            'category_id' => $category->id,
            'status' => ProjectStatus::Stopped,
            'created_at' => Carbon::parse('2026-06-01 09:00:00'),
        ]);

        $this->getJson('/api/v1/projects?status=active')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/projects?fund_type=fixed')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/projects?filter[category_id]='.$category->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/projects?created_from=2026-07-01')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/projects?created_to=2026-06-30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Variable');

        $this->getJson('/api/v1/projects?created_from=2026-07-01&created_to=2026-07-31')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/projects?search=Alpha&fund_type=fixed&status=active&filter[category_id]='.$category->id.'&created_from=2026-07-01&created_to=2026-07-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_invalid_date_range_and_invalid_tab_are_rejected(): void
    {
        $this->getJson('/api/v1/projects?created_from=2026-07-20&created_to=2026-07-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['created_to']);

        $this->getJson('/api/v1/projects?tab=unsupported')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tab']);
    }

    public function test_listing_orders_by_latest_created_at_by_default(): void
    {
        $category = $this->createCategory();

        $older = Project::factory()->create([
            'name' => 'Older',
            'category_id' => $category->id,
            'created_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);

        $newer = Project::factory()->create([
            'name' => 'Newer',
            'category_id' => $category->id,
            'created_at' => Carbon::parse('2026-07-02 09:00:00'),
        ]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }
}
