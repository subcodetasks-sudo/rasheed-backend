<?php

namespace Tests\Feature\Inventory;

use Modules\Inventory\Models\InventoryCategory;
use Modules\Inventory\Models\InventoryItem;

class InventoryCategoryApiTest extends InventoryFeatureTestCase
{
    private const ENDPOINT = '/api/v1/inventory/categories';

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_finance_gets_403(): void
    {
        $this->actAsFinanceUser();
        $this->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_inventory_can_list_create_update_delete_category(): void
    {
        $this->actAsInventoryUser();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);

        $created = $this->postJson(self::ENDPOINT, ['name' => 'مستهلكات'])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.inventory_category_created_successfully'))
            ->assertJsonPath('data.name', 'مستهلكات')
            ->json('data');

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مستهلكات');

        $this->patchJson(self::ENDPOINT.'/'.$created['id'], ['name' => 'معدات'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.inventory_category_updated_successfully'))
            ->assertJsonPath('data.name', 'معدات');

        $this->deleteJson(self::ENDPOINT.'/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.inventory_category_deleted_successfully'));

        $this->assertDatabaseMissing('inventory_categories', ['id' => $created['id']]);
    }

    public function test_super_admin_can_create_category(): void
    {
        $this->actAsSuperAdmin();

        $this->postJson(self::ENDPOINT, ['name' => 'office'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'office');
    }

    public function test_create_rejects_duplicate_name(): void
    {
        $this->actAsInventoryUser();
        $this->createInventoryCategory(['name' => 'office']);

        $this->postJson(self::ENDPOINT, ['name' => 'office'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_cannot_delete_category_with_linked_items(): void
    {
        $this->actAsInventoryUser();
        $category = $this->createInventoryCategory(['name' => 'office']);
        $project = $this->createActiveProject();

        InventoryItem::factory()->create([
            'project_id' => $project->id,
            'inventory_category_id' => $category->id,
        ]);

        $this->deleteJson(self::ENDPOINT.'/'.$category->id)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.inventory_category_has_items'));

        $this->assertDatabaseHas('inventory_categories', ['id' => $category->id]);
    }

    public function test_create_item_requires_existing_inventory_category(): void
    {
        $this->actAsInventoryUser();
        $project = $this->createActiveProject();

        $this->postJson('/api/v1/inventory/items', [
            'name' => 'Paper',
            'category_id' => 999999,
            'project_id' => $project->id,
            'unit' => 'ream',
            'opening_price' => 10,
            'opening_quantity' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors(['category_id']);

        $category = InventoryCategory::factory()->create(['name' => 'office']);

        $this->postJson('/api/v1/inventory/items', [
            'name' => 'Paper',
            'category_id' => $category->id,
            'project_id' => $project->id,
            'unit' => 'ream',
            'opening_price' => 10,
            'opening_quantity' => 50,
        ])->assertCreated()
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category.name', 'office')
            ->assertJsonPath('data.category_id', $category->id);
    }
}
