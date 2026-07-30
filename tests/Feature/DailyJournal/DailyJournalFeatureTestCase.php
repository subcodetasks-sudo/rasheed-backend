<?php

namespace Tests\Feature\DailyJournal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class DailyJournalFeatureTestCase extends TestCase
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

    protected function actAsFinanceUser(): User
    {
        return $this->actAsRole('finance');
    }

    protected function actAsSuperAdmin(): User
    {
        return $this->actAsRole('super-admin');
    }

    protected function actAsInventoryUser(): User
    {
        return $this->actAsRole('inventory');
    }

    protected function createActiveProject(array $attributes = []): Project
    {
        return Project::factory()->create(array_merge([
            'status' => ProjectStatus::Active,
            'operational_deduction_type' => OperationalDeductionType::Relative,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ], $attributes));
    }

    protected function createCategory(array $attributes = []): Category
    {
        return Category::factory()->create($attributes);
    }
}
