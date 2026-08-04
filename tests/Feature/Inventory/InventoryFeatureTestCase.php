<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\InventoryCategory;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class InventoryFeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function actAsRole(string $roleName): User
    {
        Role::findOrCreate($roleName, 'web');

        $user = User::factory()->create();
        $user->assignRole($roleName);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function actAsSuperAdmin(): User
    {
        return $this->actAsRole('super-admin');
    }

    protected function actAsInventoryUser(): User
    {
        return $this->actAsRole('inventory');
    }

    protected function actAsFinanceUser(): User
    {
        return $this->actAsRole('finance');
    }

    protected function createActiveProject(array $attributes = []): Project
    {
        return Project::factory()->create(array_merge([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ], $attributes));
    }

    protected function createInventoryCategory(array $attributes = []): InventoryCategory
    {
        return InventoryCategory::factory()->create($attributes);
    }
}
