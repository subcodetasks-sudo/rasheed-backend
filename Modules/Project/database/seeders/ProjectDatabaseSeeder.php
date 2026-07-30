<?php

namespace Modules\Project\Database\Seeders;

use Illuminate\Database\Seeder;

class ProjectDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            ProjectSeeder::class,
        ]);
    }
}
