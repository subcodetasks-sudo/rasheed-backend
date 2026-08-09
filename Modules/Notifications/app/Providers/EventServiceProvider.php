<?php

namespace Modules\Notifications\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Events\InventoryCategoryCreated;
use Modules\Inventory\Events\InventoryCategoryDeleted;
use Modules\Inventory\Events\InventoryCategoryUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Notifications\Listeners\ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate;
use Modules\Notifications\Listeners\ApplyPendingContributionFundBalanceOnDailyJournalUpdate;
use Modules\Notifications\Listeners\NotifyAdministrativeDebtSettlementActivity;
use Modules\Notifications\Listeners\NotifyCashStationActivity;
use Modules\Notifications\Listeners\NotifyCategoryActivity;
use Modules\Notifications\Listeners\NotifyDailyJournalActivity;
use Modules\Notifications\Listeners\NotifyInventoryActivity;
use Modules\Notifications\Listeners\NotifyProjectActivity;
use Modules\Notifications\Listeners\NotifySettingsActivity;
use Modules\Notifications\Listeners\NotifyUserActivity;
use Modules\Notifications\Listeners\RefreshAdministrativeDebtSettlementOnDailyJournalUpdate;
use Modules\Notifications\Listeners\RefreshAdministrativeFundOnDailyJournalUpdate;
use Modules\Notifications\Listeners\RefreshAdministrativeFundOnDebtSettlementCreated;
use Modules\Notifications\Listeners\RefreshAdministrativeFundOnOperationalFundUpdate;
use Modules\Notifications\Listeners\RefreshAdvancedReportsOnFinancialUpdate;
use Modules\Notifications\Listeners\RefreshCashFundExpensesOnDailyJournalUpdate;
use Modules\Notifications\Listeners\RefreshCashStationOnDailyJournalUpdate;
use Modules\Notifications\Listeners\RefreshDailyJournalOnInventoryAdministrativeMovement;
use Modules\Notifications\Listeners\RefreshDashboardOnFinancialUpdate;
use Modules\Notifications\Listeners\RefreshModulesOnSettingsUpdate;
use Modules\Notifications\Listeners\RefreshMonthlySummaryOnCashStationUpdate;
use Modules\Notifications\Listeners\RefreshMonthlySummaryOnDailyJournalUpdate;
use Modules\Notifications\Listeners\RefreshOperationalRateOnDailyJournalUpdate;
use Modules\Notifications\Listeners\RefreshOperationalRateOnProjectUpdate;
use Modules\Notifications\Listeners\RefreshReportsCenterOnFinancialUpdate;
use Modules\Notifications\Listeners\SyncAdministrativeDebtAlertOnFinancialChange;
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
        ProjectCreated::class => [NotifyProjectActivity::class],
        ProjectUpdated::class => [
            NotifyProjectActivity::class,
            RefreshOperationalRateOnProjectUpdate::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshAdvancedReportsOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],
        ProjectArchived::class => [NotifyProjectActivity::class],
        ProjectDeleted::class => [NotifyProjectActivity::class],
        ProjectRestored::class => [NotifyProjectActivity::class],

        CategoryCreated::class => [NotifyCategoryActivity::class],
        CategoryUpdated::class => [NotifyCategoryActivity::class],
        CategoryDeleted::class => [NotifyCategoryActivity::class],

        UserCreated::class => [NotifyUserActivity::class],
        UserUpdated::class => [NotifyUserActivity::class],
        UserDeleted::class => [NotifyUserActivity::class],
        UserStatusUpdated::class => [NotifyUserActivity::class],

        DailyJournalUpdated::class => [
            NotifyDailyJournalActivity::class,
            ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate::class,
            ApplyPendingContributionFundBalanceOnDailyJournalUpdate::class,
            SyncAdministrativeDebtAlertOnFinancialChange::class,
            RefreshCashStationOnDailyJournalUpdate::class,
            RefreshAdministrativeDebtSettlementOnDailyJournalUpdate::class,
            RefreshCashFundExpensesOnDailyJournalUpdate::class,
            RefreshMonthlySummaryOnDailyJournalUpdate::class,
            RefreshAdministrativeFundOnDailyJournalUpdate::class,
            RefreshOperationalRateOnDailyJournalUpdate::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshAdvancedReportsOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],
        AdministrativeDebtRepaid::class => [
            NotifyDailyJournalActivity::class,
            SyncAdministrativeDebtAlertOnFinancialChange::class,
            RefreshAdministrativeDebtSettlementOnDailyJournalUpdate::class,
            RefreshMonthlySummaryOnDailyJournalUpdate::class,
            RefreshOperationalRateOnDailyJournalUpdate::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshAdvancedReportsOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],

        InventoryItemCreated::class => [
            NotifyInventoryActivity::class,
            RefreshAdvancedReportsOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],
        InventoryStockMoved::class => [
            NotifyInventoryActivity::class,
            RefreshDailyJournalOnInventoryAdministrativeMovement::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshAdvancedReportsOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],
        InventoryCategoryCreated::class => [NotifyInventoryActivity::class],
        InventoryCategoryUpdated::class => [NotifyInventoryActivity::class],
        InventoryCategoryDeleted::class => [NotifyInventoryActivity::class],

        CashStationSettlementCreated::class => [
            NotifyCashStationActivity::class,
            SyncAdministrativeDebtAlertOnFinancialChange::class,
            RefreshMonthlySummaryOnCashStationUpdate::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],
        CashStationSettlementDeleted::class => [
            NotifyCashStationActivity::class,
            SyncAdministrativeDebtAlertOnFinancialChange::class,
            RefreshMonthlySummaryOnCashStationUpdate::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],
        CashStationCarriedForward::class => [
            NotifyCashStationActivity::class,
            RefreshMonthlySummaryOnCashStationUpdate::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],

        AdministrativeDebtSettlementCreated::class => [
            NotifyAdministrativeDebtSettlementActivity::class,
            SyncAdministrativeDebtAlertOnFinancialChange::class,
            RefreshAdministrativeFundOnDebtSettlementCreated::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],

        OperationalFundUpdated::class => [
            RefreshAdministrativeFundOnOperationalFundUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],

        SystemGeneralSettingsUpdated::class => [
            NotifySettingsActivity::class,
            RefreshModulesOnSettingsUpdate::class,
        ],
        MonthlyEmployeeSettingsUpdated::class => [
            NotifySettingsActivity::class,
            RefreshModulesOnSettingsUpdate::class,
        ],
    ];

    protected static $shouldDiscoverEvents = false;
}
