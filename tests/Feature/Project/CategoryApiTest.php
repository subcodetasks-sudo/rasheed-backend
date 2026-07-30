<?php

namespace Tests\Feature\Project;

use Modules\Project\Models\Category;

class CategoryApiTest extends ProjectFeatureTestCase
{
    public function test_category_endpoints_require_authentication(): void
    {
        $category = Category::factory()->create();

        $this->getJson('/api/v1/categories')->assertUnauthorized();
        $this->postJson('/api/v1/categories', [])->assertUnauthorized();
        $this->patchJson("/api/v1/categories/{$category->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/categories/{$category->id}")->assertUnauthorized();
    }

    public function test_can_list_create_update_and_delete_categories(): void
    {
        $this->actAsSuperAdmin();

        $first = Category::factory()->create(['name' => 'Zakat']);
        $second = Category::factory()->create(['name' => 'Aid']);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);

        $createResponse = $this->postJson('/api/v1/categories', [
            'name' => 'Education',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.name', 'Education');

        $createdId = $createResponse->json('data.id');

        $this->patchJson("/api/v1/categories/{$createdId}", [
            'name' => 'Education Updated',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Education Updated');

        $this->deleteJson("/api/v1/categories/{$createdId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('categories', ['id' => $createdId]);
    }

    public function test_category_writes_require_super_admin_role(): void
    {
        $this->actAsFinanceUser();

        $this->postJson('/api/v1/categories', [
            'name' => 'Blocked Category',
        ])->assertForbidden();

        $existing = Category::factory()->create(['name' => 'Unique Category']);

        $this->actAsSuperAdmin();

        $this->postJson('/api/v1/categories', [
            'name' => '',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/v1/categories', [
            'name' => 'Unique Category',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->patchJson("/api/v1/categories/{$existing->id}", [
            'name' => 'Unique Category',
        ])->assertOk();

        Category::factory()->create(['name' => 'Other Category']);

        $this->patchJson("/api/v1/categories/{$existing->id}", [
            'name' => 'Other Category',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
