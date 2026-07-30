<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Authorization\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Project\Database\Seeders\ProjectDatabaseSeeder;
use Modules\Settings\Database\Seeders\SettingsDatabaseSeeder;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SettingsDatabaseSeeder::class,
        ]);

        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web']
        );

        $admin = User::firstOrCreate(
            ['user_name' => 'super_admin'],
            [
                'uuid' => (string) Str::uuid(),
                'full_name' => 'Super Admin',
                'email' => 'admin@system.com',
                'password' => bcrypt('password123'),
                'status' => 'active',
            ]
        );

        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole($superAdminRole);
        }

        $this->call([
            ProjectDatabaseSeeder::class,
        ]);
    }
}
