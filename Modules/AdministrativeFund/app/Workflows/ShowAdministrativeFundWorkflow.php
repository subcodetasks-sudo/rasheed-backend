<?php

namespace Modules\AdministrativeFund\Workflows;

use Modules\AdministrativeFund\Actions\BuildAdministrativeFundAction;

class ShowAdministrativeFundWorkflow
{
    public function __construct(
        private readonly BuildAdministrativeFundAction $buildAdministrativeFundAction,
    ) {}

    public function handle(int $month, int $year): array
    {
        return $this->buildAdministrativeFundAction->execute($month, $year);
    }
}
