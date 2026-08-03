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

    /**
     * Seed org Administrative Percentage Balance by recording fees on a donor project.
     * Default 12% fee on 10_000 income ⇒ 1_200 available (minus any existing debits).
     */
    protected function seedAdminPercentageBalance(float $income = 10000.0, ?string $journalDate = null): Project
    {
        $donor = $this->createActiveProject([
            'name' => 'Admin Fee Donor '.uniqid(),
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => false,
            'administrative_fee_percentage' => 12,
        ]);

        $this->putJson('/api/v1/daily-journals', [
            'journal_date' => $journalDate ?? now()->subDays(2)->toDateString(),
            'entries' => [['project_id' => $donor->id, 'daily_income' => $income]],
        ])->assertOk();

        return $donor;
    }
}
