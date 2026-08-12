<?php

namespace Modules\AuditLog\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\AdministrativeFund\Events\AdministrativeFundUpdated;
use Modules\AuditLog\Listeners\RecordAdministrativeDebtSettlementAuditLog;
use Modules\AuditLog\Listeners\RecordAdministrativeFundAuditLog;
use Modules\AuditLog\Listeners\RecordCashStationAuditLog;
use Modules\AuditLog\Listeners\RecordCategoryAuditLog;
use Modules\AuditLog\Listeners\RecordDailyJournalAuditLog;
use Modules\AuditLog\Listeners\RecordInventoryAuditLog;
use Modules\AuditLog\Listeners\RecordOperationalFundAuditLog;
use Modules\AuditLog\Listeners\RecordProjectAuditLog;
use Modules\AuditLog\Listeners\RecordSettingsAuditLog;
use Modules\AuditLog\Listeners\RecordUserAuditLog;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Events\InventoryCategoryCreated;
use Modules\Inventory\Events\InventoryCategoryDeleted;
use Modules\Inventory\Events\InventoryCategoryUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryItemDeleted;
use Modules\Inventory\Events\InventoryMovementDeleted;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\OperationalFund\Events\OperationalFundUpdated;
use Modules\Project\Events\CategoryCreated;
use Modules\Project\Events\CategoryDeleted;
use Modules\Project\Events\CategoryUpdated;
use Modules\Project\Events\ProjectArchived;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Events\ProjectDeleted;
use Modules\Project\Events\ProjectRestored;
use Modules\Project\Events\ProjectUpdated;
use Modules\Settings\Events\MonthlyEmployeeSettingsUpdated;
use Modules\Settings\Events\SystemGeneralSettingsUpdated;
use Modules\User\app\Events\UserCreated;
use Modules\User\app\Events\UserDeleted;
use Modules\User\app\Events\UserStatusUpdated;
use Modules\User\app\Events\UserUpdated;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ProjectCreated::class => [RecordProjectAuditLog::class],
        ProjectUpdated::class => [RecordProjectAuditLog::class],
        ProjectArchived::class => [RecordProjectAuditLog::class],
        ProjectDeleted::class => [RecordProjectAuditLog::class],
        ProjectRestored::class => [RecordProjectAuditLog::class],

        CategoryCreated::class => [RecordCategoryAuditLog::class],
        CategoryUpdated::class => [RecordCategoryAuditLog::class],
        CategoryDeleted::class => [RecordCategoryAuditLog::class],

        UserCreated::class => [RecordUserAuditLog::class],
        UserUpdated::class => [RecordUserAuditLog::class],
        UserDeleted::class => [RecordUserAuditLog::class],
        UserStatusUpdated::class => [RecordUserAuditLog::class],

        DailyJournalUpdated::class => [RecordDailyJournalAuditLog::class],
        AdministrativeDebtRepaid::class => [RecordDailyJournalAuditLog::class],

        InventoryItemCreated::class => [RecordInventoryAuditLog::class],
        InventoryStockMoved::class => [RecordInventoryAuditLog::class],
        InventoryCategoryCreated::class => [RecordInventoryAuditLog::class],
        InventoryCategoryUpdated::class => [RecordInventoryAuditLog::class],
        InventoryCategoryDeleted::class => [RecordInventoryAuditLog::class],
        InventoryItemDeleted::class => [RecordInventoryAuditLog::class],
        InventoryMovementDeleted::class => [RecordInventoryAuditLog::class],

        CashStationSettlementCreated::class => [RecordCashStationAuditLog::class],
        CashStationSettlementDeleted::class => [RecordCashStationAuditLog::class],
        CashStationCarriedForward::class => [RecordCashStationAuditLog::class],

        AdministrativeDebtSettlementCreated::class => [RecordAdministrativeDebtSettlementAuditLog::class],

        OperationalFundUpdated::class => [RecordOperationalFundAuditLog::class],
        AdministrativeFundUpdated::class => [RecordAdministrativeFundAuditLog::class],

        SystemGeneralSettingsUpdated::class => [RecordSettingsAuditLog::class],
        MonthlyEmployeeSettingsUpdated::class => [RecordSettingsAuditLog::class],
    ];

    protected static $shouldDiscoverEvents = false;
}
