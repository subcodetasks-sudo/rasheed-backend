<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Inventory\Models\InventoryItem;
use Modules\Project\Models\Project;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        $opening = fake()->randomFloat(2, 0, 100);

        return [
            'code' => (string) Str::ulid(),
            'project_id' => Project::factory(),
            'name' => fake()->words(3, true),
            'inventory_category_id' => InventoryCategory::factory(),
            'unit' => fake()->randomElement(['piece', 'box', 'kg']),
            'latest_incoming_price' => fake()->randomFloat(2, 1, 100),
            'opening_quantity' => $opening,
            'total_incoming_quantity' => 0,
            'total_outgoing_quantity' => 0,
            'current_balance' => $opening,
            'minimum_stock_level' => 0,
            'notes' => null,
        ];
    }
}
