<?php

namespace Modules\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'inventory', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);

        $viewAuditLogs = Permission::firstOrCreate([
            'name' => 'view-audit-logs',
            'guard_name' => 'web',
        ]);

        Role::findByName('super-admin', 'web')->givePermissionTo($viewAuditLogs);
    }
}
