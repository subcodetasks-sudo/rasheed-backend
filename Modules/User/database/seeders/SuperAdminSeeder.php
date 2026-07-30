<?php

namespace Modules\User\database\seeders;

use Illuminate\Database\Seeder;
use Modules\User\app\Models\User;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super-admin']);

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'full_name' => 'Super Admin',
                'user_name' => 'admin',
                'password' => bcrypt('password'),
                'status' => 'active',
            ]
        );

        if (!$admin->hasRole('super-admin')) {
            $admin->assignRole($role);
        }
    }
}