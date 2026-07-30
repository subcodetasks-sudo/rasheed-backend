<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
// use Modules\User\app\Models\Role;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = ['Super Admin', 'Manager','Inventory'];

        foreach ($roles as $roleName) {
          Role::create(['name' => $roleName]);
        }
    }
}
