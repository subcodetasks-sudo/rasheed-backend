<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Project\Models\Category;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class ProjectFeatureTestCase extends TestCase
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

    protected function actAsSuperAdmin(mixed ...$unused): User
    {
        return $this->actAsRole('super-admin');
    }

    protected function actAsFinanceUser(): User
    {
        return $this->actAsRole('finance');
    }

    protected function actAsInventoryUser(): User
    {
        return $this->actAsRole('inventory');
    }

    protected function actAsUserWithPermissions(array $permissions = []): User
    {
        if ($permissions === []) {
            $user = User::factory()->create();
            Sanctum::actingAs($user);

            return $user;
        }

        return $this->actAsFinanceUser();
    }

    protected function createCategory(array $attributes = []): Category
    {
        return Category::factory()->create($attributes);
    }
}
