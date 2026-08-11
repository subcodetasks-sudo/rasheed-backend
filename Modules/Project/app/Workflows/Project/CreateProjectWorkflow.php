<?php

namespace Modules\Project\Workflows\Project;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Project\CalculateOperationalSettingsAction;
use Modules\Project\Actions\Project\CreateProjectAction;
use Modules\Project\Actions\Project\ResolveAdminFeePercentageAction;
use Modules\Project\Actions\Project\SeedAdministrativeFeeRateAction;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Models\Project;

class CreateProjectWorkflow
{
    public function __construct(
        private readonly CalculateOperationalSettingsAction $calculateOperationalSettingsAction,
        private readonly ResolveAdminFeePercentageAction $resolveAdminFeePercentageAction,
        private readonly CreateProjectAction $createProjectAction,
        private readonly SeedAdministrativeFeeRateAction $seedAdministrativeFeeRateAction,
    ) {}

    public function handle(ProjectData $data): Project
    {
        return DB::transaction(function () use ($data) {
            $settings = $this->calculateOperationalSettingsAction->execute($data);

            $percentage = $data->administrativeFeePercentage ?? $this->resolveAdminFeePercentageAction->execute();

            $project = $this->createProjectAction->execute([
                ...$data->toArray(),
                ...$settings,
                'administrative_fee_percentage' => $percentage,
                'created_by' => auth()->user()?->uuid,
                'updated_by' => auth()->user()?->uuid,
            ]);

            $this->seedAdministrativeFeeRateAction->execute($project, $percentage, $project->created_at);

            ProjectCreated::dispatch($project);

            return $project;
        });
    }
}
