<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\InventoryCategory;

class InventoryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'مستهلكات'],
            ['name' => 'معدات'],
            ['name' => 'أثاث'],
            ['name' => 'قرطاسية'],
            ['name' => 'قطع غيار'],
            ['name' => 'مواد غذائية'],
            ['name' => 'أدوية ومستلزمات طبية'],
        ];

        foreach ($categories as $category) {
            InventoryCategory::query()->firstOrCreate($category);
        }
    }
}
