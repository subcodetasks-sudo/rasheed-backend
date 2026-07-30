<?php

namespace Modules\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = 'admin@example.com';

        $user = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password123'), 
                'email_verified_at' => now(),
            ]
        );

        $role = Role::where('name', 'super-admin')->first();

        if ($role && !$user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}