<?php

namespace Modules\MonthlySummary\Workflows;

use Modules\MonthlySummary\Actions\ListBeneficiaryOptionsAction;
use Modules\MonthlySummary\Enums\ContributionType;

class ListBeneficiaryOptionsWorkflow
{
    public function __construct(
        private readonly ListBeneficiaryOptionsAction $listBeneficiaryOptionsAction,
    ) {}

    public function handle(
        int $month,
        int $year,
        int $fromProjectId,
        ContributionType $contributionType,
    ): array {
        return $this->listBeneficiaryOptionsAction->execute(
            $month,
            $year,
            $fromProjectId,
            $contributionType,
        );
    }
}
