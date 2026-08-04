<?php

namespace Modules\MonthlySummary\Actions;

use Modules\CashStation\Actions\BuildCashStationAction;
use Modules\DailyJournal\Models\DailyJournalEntry;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\Project\Exceptions\BusinessException;
use Modules\Project\Models\Project;

class ValidateMonthlySummaryContributionAction
{
    public function __construct(
        private readonly BuildCashStationAction $buildCashStationAction,
        private readonly ListBeneficiaryOptionsAction $listBeneficiaryOptionsAction,
    ) {}

    /**
     * @return array{max_allowed: float, contributor_surplus: float, beneficiary_need: float}
     */
    public function execute(
        int $year,
        int $month,
        int $fromProjectId,
        int $toProjectId,
        ContributionType $contributionType,
        float $amount,
    ): array {
        if ($fromProjectId === $toProjectId) {
            throw new BusinessException(__('messages.monthly_summary_contribution_self_not_allowed'));
        }

        if ($amount <= 0) {
            throw new BusinessException(__('messages.monthly_summary_contribution_amount_invalid'));
        }

        $from = Project::query()->find($fromProjectId);
        $to = Project::query()->find($toProjectId);

        if ($from === null || ! $from->isActive()) {
            throw new BusinessException(__('messages.monthly_summary_contributor_inactive'));
        }

        if ($to === null || ! $to->isActive()) {
            throw new BusinessException(__('messages.monthly_summary_beneficiary_inactive'));
        }

        if ((int) $from->category_id !== (int) $to->category_id) {
            throw new BusinessException(__('messages.monthly_summary_contribution_different_category'));
        }

        $surplus = round($this->buildCashStationAction->transferableBalance($fromProjectId, $month, $year), 2);
        if ($surplus <= 0) {
            throw new BusinessException(__('messages.monthly_summary_contributor_requires_surplus'));
        }

        $need = $this->listBeneficiaryOptionsAction->remainingNeed(
            $toProjectId,
            $month,
            $year,
            $contributionType,
        );

        if ($need <= 0) {
            throw new BusinessException(
                $contributionType === ContributionType::FundDeficit
                    ? __('messages.monthly_summary_beneficiary_no_fund_deficit')
                    : __('messages.monthly_summary_beneficiary_no_administrative_debt')
            );
        }

        $maxAllowed = round(min($surplus, $need), 2);
        $requested = round($amount, 2);

        if ($requested > $maxAllowed + 0.001) {
            throw new BusinessException(
                __('messages.monthly_summary_contribution_exceeds_maximum', [
                    'max' => number_format($maxAllowed, 2, '.', ''),
                ])
            );
        }

        // Serialize concurrent surplus use against each project's journal tip.
        foreach ([$fromProjectId, $toProjectId] as $projectId) {
            DailyJournalEntry::query()
                ->where('project_id', $projectId)
                ->whereDate('journal_date', '<=', sprintf(
                    '%04d-%02d-%02d',
                    $year,
                    $month,
                    (int) date('t', mktime(0, 0, 0, $month, 1, $year)),
                ))
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
        }

        return [
            'max_allowed' => $maxAllowed,
            'contributor_surplus' => $surplus,
            'beneficiary_need' => $need,
        ];
    }
}
