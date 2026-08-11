<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Authorization\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Inventory\Database\Seeders\InventoryDatabaseSeeder;
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

        $accounts = [
            [
                'role' => 'super-admin',
                'user_name' => 'super_admin',
                'full_name' => 'Super Admin',
                'email' => 'admin@system.com',
            ],
            [
                'role' => 'finance',
                'user_name' => 'finance_user',
                'full_name' => 'Finance Manager',
                'email' => 'finance@system.com',
            ],
            [
                'role' => 'inventory',
                'user_name' => 'inventory_user',
                'full_name' => 'Inventory Manager',
                'email' => 'inventory@system.com',
            ],
        ];

        foreach ($accounts as $account) {
            $role = Role::firstOrCreate([
                'name' => $account['role'],
                'guard_name' => 'api',
            ]);

            $user = User::query()->where('user_name', $account['user_name'])->first();

            if (! $user) {
                $user = new User;
                $user->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'user_name' => $account['user_name'],
                    'full_name' => $account['full_name'],
                    'email' => $account['email'],
                    'password' => 'password123',
                    'status' => 'active',
                ])->save();
            }

            if (! $user->hasRole($account['role'])) {
                $user->assignRole($role);
            }
        }

        // $this->call([
        //     ProjectDatabaseSeeder::class,
        //     InventoryDatabaseSeeder::class,
        // ]);
    }
}
