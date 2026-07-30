<?php

namespace Tests\Unit\Project;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\Project\Queries\ListProjectsQuery;
use Tests\TestCase;

class ListProjectsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginate_applies_tabs_filters_dates_and_default_sort(): void
    {
        $category = Category::factory()->create(['name' => 'Relief']);

        $olderFixed = Project::factory()->fixedFund()->create([
            'name' => 'Older Fixed',
            'category_id' => $category->id,
            'status' => ProjectStatus::Active,
            'created_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);

        $newerFixed = Project::factory()->fixedFund()->create([
            'name' => 'Newer Fixed',
            'category_id' => $category->id,
            'status' => ProjectStatus::Active,
            'created_at' => Carbon::parse('2026-07-15 09:00:00'),
        ]);

        Project::factory()->variableFund()->create([
            'name' => 'Variable Project',
            'category_id' => $category->id,
            'status' => ProjectStatus::Stopped,
        ]);

        Project::factory()->fixedFund()->archived()->create([
            'name' => 'Archived Fixed',
            'category_id' => $category->id,
        ]);

        $query = app(ListProjectsQuery::class);

        $tabPaginator = $query->paginate(new Request([
            'tab' => 'fixed',
            'created_from' => '2026-07-01',
            'created_to' => '2026-07-31',
        ]));

        $this->assertSame([$newerFixed->id, $olderFixed->id], collect($tabPaginator->items())->pluck('id')->all());

        $filterPaginator = $query->paginate(new Request([
            'fund_type' => FundType::Variable->value,
            'status' => ProjectStatus::Stopped->value,
            'filter' => ['category_id' => $category->id],
        ]));

        $this->assertCount(1, $filterPaginator->items());
        $this->assertSame('Variable Project', $filterPaginator->items()[0]->name);
    }

    public function test_paginate_searches_project_names_and_not_category_text(): void
    {
        $category = Category::factory()->create(['name' => 'Education']);
        $otherCategory = Category::factory()->create(['name' => 'Health']);

        Project::factory()->create([
            'name' => 'Alpha Care',
            'category_id' => $category->id,
        ]);

        $matchingByName = Project::factory()->create([
            'name' => 'Education Support',
            'category_id' => $otherCategory->id,
        ]);

        $query = app(ListProjectsQuery::class);

        $paginator = $query->paginate(new Request([
            'search' => 'Education',
        ]));

        $this->assertCount(1, $paginator->items());
        $this->assertSame($matchingByName->id, $paginator->items()[0]->id);
    }

    public function test_paginate_eager_loads_relations_without_n_plus_one_growth(): void
    {
        $category = Category::factory()->create();
        Project::factory()->count(5)->create(['category_id' => $category->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $paginator = app(ListProjectsQuery::class)->paginate(new Request([
            'per_page' => 5,
        ]));

        $queries = DB::getQueryLog();

        foreach ($paginator->items() as $project) {
            $this->assertTrue($project->relationLoaded('category'));
            $this->assertTrue($project->relationLoaded('creator'));
            $this->assertTrue($project->relationLoaded('updater'));
        }

        $this->assertLessThanOrEqual(5, count($queries));
    }
}
