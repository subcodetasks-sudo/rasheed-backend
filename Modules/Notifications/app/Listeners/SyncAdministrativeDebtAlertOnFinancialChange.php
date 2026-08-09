<?php

namespace Modules\Notifications\Listeners;

use Modules\AdministrativeDebtSettlement\Events\AdministrativeDebtSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementCreated;
use Modules\CashStation\Events\CashStationSettlementDeleted;
use Modules\DailyJournal\Events\AdministrativeDebtRepaid;
use Modules\DailyJournal\Events\DailyJournalUpdated;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\Notifications\Actions\SyncAdministrativeDebtAlertAction;

class SyncAdministrativeDebtAlertOnFinancialChange
{
    public function __construct(
        private readonly SyncAdministrativeDebtAlertAction $syncAdministrativeDebtAlertAction,
    ) {}

    public function handle(
        DailyJournalUpdated|AdministrativeDebtRepaid|AdministrativeDebtSettlementCreated|CashStationSettlementCreated|CashStationSettlementDeleted $event,
    ): void {
        if ($event instanceof DailyJournalUpdated) {
            $projectIds = $event->entries->pluck('project_id')->unique()->filter()->values()->all();
            $this->syncAdministrativeDebtAlertAction->executeMany(
                $projectIds,
                $event->journalDate->toDateString(),
            );

            return;
        }

        if ($event instanceof AdministrativeDebtRepaid) {
            $this->syncAdministrativeDebtAlertAction->execute((int) $event->entry->project_id);

            return;
        }

        if ($event instanceof AdministrativeDebtSettlementCreated) {
            $this->syncAdministrativeDebtAlertAction->execute((int) $event->settlement->project_id);

            return;
        }

        if ($event instanceof CashStationSettlementCreated) {
            $settlement = $event->settlement;
            if ($settlement->contribution_type === ContributionType::AdministrativeDebt) {
                $this->syncAdministrativeDebtAlertAction->execute((int) $settlement->to_project_id);
            }

            return;
        }

        if ($event->contributionType === ContributionType::AdministrativeDebt->value
            && $event->toProjectId !== null) {
            $this->syncAdministrativeDebtAlertAction->execute((int) $event->toProjectId);
        }
    }
}
