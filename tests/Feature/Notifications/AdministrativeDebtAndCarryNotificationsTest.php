<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AdministrativeDebtSettlement\Enums\AdministrativeDebtSettlementStatus;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\AdministrativeDebtSettlement\Models\AdministrativeDebtSettlement;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Models\CashStationMonthCarry;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\Notifications\Actions\SyncAdministrativeDebtAlertAction;
use Modules\Notifications\Enums\NotificationType;
use Modules\Notifications\Models\Notification;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrativeDebtAndCarryNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function actAsFinance(): User
    {
        Role::findOrCreate('finance', 'web');
        $user = User::factory()->create();
        $user->assignRole('finance');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_administrative_debt_alert_upserts_and_resolves(): void
    {
        $this->actAsFinance();
        $project = Project::factory()->create(['name' => 'مشروع الدين']);

        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'journal_date' => now()->toDateString(),
            'accumulated_administrative_debt' => 10000,
        ]);

        $sync = app(SyncAdministrativeDebtAlertAction::class);
        $sync->execute($project->id);
        $sync->execute($project->id);

        $alerts = Notification::query()
            ->where('project_id', $project->id)
            ->where('type', NotificationType::Warning->value)
            ->get()
            ->filter(fn (Notification $n) => ($n->meta['action'] ?? null) === SyncAdministrativeDebtAlertAction::META_ACTION);

        $this->assertCount(1, $alerts);
        $alert = $alerts->first();
        $this->assertSame(__('messages.notification_administrative_debt_alert_title'), $alert->title);
        $this->assertSame(10000.0, (float) $alert->meta['remaining_debt']);
        $this->assertStringContainsString('مشروع الدين', $alert->message);

        DailyJournalEntry::query()->where('project_id', $project->id)->update([
            'accumulated_administrative_debt' => 7000,
        ]);
        $sync->execute($project->id);

        $alert->refresh();
        $this->assertSame(7000.0, (float) $alert->meta['remaining_debt']);
        $this->assertSame(1, Notification::query()->where('project_id', $project->id)->where('type', NotificationType::Warning->value)->count());

        DailyJournalEntry::query()->where('project_id', $project->id)->update([
            'accumulated_administrative_debt' => 0,
        ]);
        $sync->execute($project->id);

        $this->assertFalse(
            Notification::query()
                ->where('project_id', $project->id)
                ->get()
                ->contains(fn (Notification $n) => ($n->meta['action'] ?? null) === SyncAdministrativeDebtAlertAction::META_ACTION)
        );
    }

    public function test_no_alert_when_debt_is_zero(): void
    {
        $this->actAsFinance();
        $project = Project::factory()->create();

        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'journal_date' => now()->toDateString(),
            'accumulated_administrative_debt' => 0,
        ]);

        app(SyncAdministrativeDebtAlertAction::class)->execute($project->id);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_carry_forward_notifies_once_as_info(): void
    {
        $this->actAsFinance();

        $carry = CashStationMonthCarry::query()->create([
            'from_year' => 2026,
            'from_month' => 7,
            'to_year' => 2026,
            'to_month' => 8,
            'carried_by' => null,
        ]);

        event(new CashStationCarriedForward($carry));
        event(new CashStationCarriedForward($carry));

        $notifications = Notification::query()
            ->where('type', NotificationType::Info->value)
            ->get()
            ->filter(fn (Notification $n) => ($n->meta['action'] ?? null) === 'carried_forward');

        $this->assertCount(1, $notifications);
        $notification = $notifications->first();
        $this->assertSame(__('messages.notification_cash_station_carried_forward_title'), $notification->title);
        $this->assertSame($carry->id, $notification->meta['carry_id']);
        $this->assertStringContainsString('7/2026', $notification->message);
        $this->assertArrayHasKey('executed_at', $notification->meta);
    }

    public function test_ads_payment_notifies_once_with_paid_and_remaining(): void
    {
        $this->actAsFinance();
        $project = Project::factory()->create(['name' => 'مشروع السداد']);

        DailyJournalEntry::factory()->create([
            'project_id' => $project->id,
            'journal_date' => now()->endOfMonth()->toDateString(),
            'accumulated_administrative_debt' => 2000,
        ]);

        $settlement = AdministrativeDebtSettlement::query()->create([
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'project_id' => $project->id,
            'surplus_at_settlement' => 5000,
            'recoverable_amount' => 3000,
            'allocated_cash_box' => 0,
            'allocated_current_debt' => 3000,
            'allocated_carried_debt' => 0,
            'status' => AdministrativeDebtSettlementStatus::Paid,
            'settled_by' => null,
        ]);

        event(new AdministrativeDebtSettlementCreated($settlement));
        event(new AdministrativeDebtSettlementCreated($settlement));

        $notifications = Notification::query()
            ->where('type', NotificationType::Info->value)
            ->get()
            ->filter(fn (Notification $n) => ($n->meta['action'] ?? null) === 'admin_debt_settlement_created');

        $this->assertCount(1, $notifications);
        $notification = $notifications->first();
        $this->assertSame(__('messages.notification_admin_debt_settlement_created_title'), $notification->title);
        $this->assertSame($settlement->id, $notification->meta['settlement_id']);
        $this->assertSame(3000.0, (float) $notification->meta['paid_amount']);
        $this->assertSame(2000.0, (float) $notification->meta['remaining_debt']);
        $this->assertStringContainsString('مشروع السداد', $notification->message);
    }
}
