<?php

namespace Modules\MonthlySummary\Workflows;

use Modules\MonthlySummary\Actions\ListContributorOptionsAction;

class ListContributorOptionsWorkflow
{
    public function __construct(
        private readonly ListContributorOptionsAction $listContributorOptionsAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->listContributorOptionsAction->execute($month, $year);
    }
}
