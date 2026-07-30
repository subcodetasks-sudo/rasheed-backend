<?php

namespace Modules\Project\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Project\Models\Category;

class CategorySeeder extends Seeder
{
  public function run(): void
  {
    $categories = [
      ['name' => 'مشاريع مائية'],
      ['name' => 'مشاريع صحية'],
      ['name' => 'مشاريع تعليمية'],
      ['name' => 'مشاريع إغاثية'],
      ['name' => 'الزكاة'],
    ];

    foreach ($categories as $category) {
      Category::firstOrCreate($category);
    }
  }
}