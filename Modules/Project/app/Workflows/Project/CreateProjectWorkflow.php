<?php

namespace Modules\Project\Workflows\Project;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Project\CalculateOperationalSettingsAction;
use Modules\Project\Actions\Project\CreateProjectAction;
use Modules\Project\Actions\Project\ResolveAdminFeePercentageAction;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Events\ProjectCreated;
use Modules\Project\Models\Project;

class CreateProjectWorkflow
{
    public function __construct(
        private readonly CalculateOperationalSettingsAction $calculateOperationalSettingsAction,
        private readonly ResolveAdminFeePercentageAction $resolveAdminFeePercentageAction,
        private readonly CreateProjectAction $createProjectAction,
    ) {}

    public function handle(ProjectData $data): Project
    {
        return DB::transaction(function () use ($data) {
            $settings = $this->calculateOperationalSettingsAction->execute($data);

            $project = $this->createProjectAction->execute([
                ...$data->toArray(),
                ...$settings,
                'administrative_fee_percentage' => $this->resolveAdminFeePercentageAction->execute(),
                'created_by' => auth()->user()?->uuid,
                'updated_by' => auth()->user()?->uuid,
            ]);

            ProjectCreated::dispatch($project);

            return $project;
        });
    }
}
