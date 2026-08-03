<?php

namespace Modules\Notifications\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\CashStation\Events\CashStationCarriedForward;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\Inventory\Events\InventoryItemCreated;
use Modules\Inventory\Events\InventoryStockMoved;
use Modules\Notifications\Listeners\NotifyCashStationActivity;
use Modules\Notifications\Listeners\NotifyDailyJournalActivity;
use Modules\Notifications\Listeners\NotifyInventoryActivity;
use Modules\Notifications\Listeners\NotifyProjectActivity;
use Modules\Notifications\Listeners\RefreshAdministrativeDebtSettlementOnDailyJournalUpdate;
use Modules\Notifications\Listeners\RefreshCashStationOnDailyJournalUpdate;
use Modules\Project\Events\ProjectArchived;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Events\ProjectDeleted;
use Modules\Project\Events\ProjectRestored;
use Modules\Project\Events\ProjectUpdated;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ProjectCreated::class => [NotifyProjectActivity::class],
        ProjectUpdated::class => [NotifyProjectActivity::class],
        ProjectArchived::class => [NotifyProjectActivity::class],
        ProjectDeleted::class => [NotifyProjectActivity::class],
        ProjectRestored::class => [NotifyProjectActivity::class],

        DailyJournalUpdated::class => [
            NotifyDailyJournalActivity::class,
            RefreshCashStationOnDailyJournalUpdate::class,
            RefreshAdministrativeDebtSettlementOnDailyJournalUpdate::class,
        ],
        AdministrativeDebtRepaid::class => [
            NotifyDailyJournalActivity::class,
            RefreshAdministrativeDebtSettlementOnDailyJournalUpdate::class,
        ],

        InventoryItemCreated::class => [NotifyInventoryActivity::class],
        InventoryStockMoved::class => [NotifyInventoryActivity::class],

        CashStationSettlementCreated::class => [NotifyCashStationActivity::class],
        CashStationSettlementDeleted::class => [NotifyCashStationActivity::class],
        CashStationCarriedForward::class => [NotifyCashStationActivity::class],
    ];

    protected static $shouldDiscoverEvents = false;
}
