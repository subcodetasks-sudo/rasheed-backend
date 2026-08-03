<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Enums\InventoryExpenseType;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Events\NotificationCreated;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Rules\InventoryStockNotificationRule;
use Modules\Notifications\Services\NotificationService;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationsRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_realtime_auth_requires_authentication(): void
    {
        $this->getJson('/api/v1/realtime/auth')->assertUnauthorized();
    }

    public function test_realtime_auth_returns_user_payload(): void
    {
        $user = $this->actAs('finance');

        $this->getJson('/api/v1/realtime/auth')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonFragment(['roles' => ['finance']]);
    }

    public function test_project_created_persists_activity_notification_and_broadcasts(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->actAs('super-admin');
        $category = Category::factory()->create();

        $this->postJson('/api/v1/projects', [
            'name' => 'Realtime Project',
            'category_id' => $category->id,
            'fund_type' => FundType::Fixed->value,
            'status' => ProjectStatus::Active->value,
            'operational_deduction_type' => OperationalDeductionType::Exempt->value,
            'administrative_exempt' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Activity->value,
            'title' => __('messages.notification_project_created_title'),
        ]);

        Event::assertDispatched(NotificationCreated::class);
    }

    public function test_stock_rule_emits_low_and_out_of_stock_on_threshold_cross(): void
    {
        Event::fake([NotificationCreated::class]);

        $rule = app(InventoryStockNotificationRule::class);
        $item = new \Modules\Inventory\Models\InventoryItem([
            'id' => 1,
            'name' => 'Gloves',
            'code' => 'INV-1',
            'current_balance' => 4,
            'minimum_stock_level' => 5,
        ]);

        $rule->evaluate($item, ['previous_balance' => 10.0]);

        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Warning->value,
        ]);

        $item->current_balance = 0;
        $rule->evaluate($item, ['previous_balance' => 4.0]);

        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Danger->value,
        ]);

        Event::assertDispatched(NotificationCreated::class);
    }

    public function test_outgoing_stock_crossing_minimum_creates_low_stock_notification(): void
    {
        $this->actAs('inventory');
        $project = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Gloves',
            'category' => 'safety',
            'project_id' => $project->id,
            'unit' => 'box',
            'opening_price' => 5,
            'opening_quantity' => 10,
            'minimum_stock_level' => 5,
        ])->assertCreated()->json('data.id');

        $this->assertSame(
            0,
            Notification::query()->where('type', NotificationType::Warning)->count()
        );

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 6,
            'beneficiary_project_id' => $project->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Warning->value,
            'title' => __('messages.notification_low_stock_title'),
        ]);
    }

    public function test_outgoing_to_zero_creates_out_of_stock_notification(): void
    {
        $this->actAs('inventory');
        $project = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $itemId = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Mask',
            'category' => 'safety',
            'project_id' => $project->id,
            'unit' => 'box',
            'opening_price' => 5,
            'opening_quantity' => 10,
            'minimum_stock_level' => 5,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/inventory/movements/outgoing', [
            'inventory_item_id' => $itemId,
            'quantity' => 10,
            'beneficiary_project_id' => $project->id,
            'expense_type' => InventoryExpenseType::Operational->value,
        ])->assertCreated();

        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Danger->value,
            'title' => __('messages.notification_out_of_stock_title'),
        ]);
    }

    public function test_inventory_item_created_event_is_dispatched(): void
    {
        Event::fake([InventoryItemCreated::class, InventoryStockMoved::class, NotificationCreated::class]);

        $this->actAs('inventory');
        $project = Project::factory()->create([
            'operational_deduction_type' => OperationalDeductionType::Exempt,
            'administrative_exempt' => true,
        ]);

        $this->postJson('/api/v1/inventory/items', [
            'name' => 'Paper',
            'category' => 'office',
            'project_id' => $project->id,
            'unit' => 'ream',
            'opening_price' => 10,
            'opening_quantity' => 50,
            'minimum_stock_level' => 5,
        ])->assertCreated();

        Event::assertDispatched(InventoryItemCreated::class);
    }

    public function test_notification_service_broadcasts_created_event(): void
    {
        Event::fake([NotificationCreated::class]);

        app(NotificationService::class)->notifyInfo('Hello', 'World');

        Event::assertDispatched(NotificationCreated::class);
        $this->assertDatabaseHas('activity_notifications', [
            'type' => NotificationType::Info->value,
            'title' => 'Hello',
        ]);
    }
}
