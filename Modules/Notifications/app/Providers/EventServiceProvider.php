<?php

namespace Modules\Notifications\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Notifications\Listeners\ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate;
use Modules\Notifications\Listeners\ApplyPendingContributionFundBalanceOnDailyJournalUpdate;
use Modules\Notifications\Listeners\NotifyCashStationActivity;
use Modules\Notifications\Listeners\NotifyDailyJournalActivity;
use Modules\Notifications\Listeners\NotifyInventoryActivity;
use Modules\Notifications\Listeners\NotifyProjectActivity;
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
use Modules\OperationalFund\Events\OperationalFundUpdated;
use Modules\Project\Events\ProjectArchived;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Events\ProjectDeleted;
use Modules\Project\Events\ProjectRestored;
use Modules\Project\Events\ProjectUpdated;
use Modules\Settings\Events\MonthlyEmployeeSettingsUpdated;
use Modules\Settings\Events\SystemGeneralSettingsUpdated;

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

        DailyJournalUpdated::class => [
            NotifyDailyJournalActivity::class,
            ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate::class,
            ApplyPendingContributionFundBalanceOnDailyJournalUpdate::class,
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

        CashStationSettlementCreated::class => [
            NotifyCashStationActivity::class,
            RefreshMonthlySummaryOnCashStationUpdate::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],
        CashStationSettlementDeleted::class => [
            NotifyCashStationActivity::class,
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
            RefreshAdministrativeFundOnDebtSettlementCreated::class,
            RefreshReportsCenterOnFinancialUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],

        OperationalFundUpdated::class => [
            RefreshAdministrativeFundOnOperationalFundUpdate::class,
            RefreshDashboardOnFinancialUpdate::class,
        ],

        SystemGeneralSettingsUpdated::class => [
            RefreshModulesOnSettingsUpdate::class,
        ],
        MonthlyEmployeeSettingsUpdated::class => [
            RefreshModulesOnSettingsUpdate::class,
        ],
    ];

    protected static $shouldDiscoverEvents = false;
}
