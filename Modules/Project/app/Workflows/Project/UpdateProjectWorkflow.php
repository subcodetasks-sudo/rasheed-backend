<?php

namespace Modules\Project\Workflows\Project;

use Illuminate\Support\Facades\DB;
use Modules\Project\Actions\Project\CalculateOperationalSettingsAction;
use Modules\Project\Actions\Project\UpdateProjectAction;
use Modules\Project\DTOs\ProjectData;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Events\ProjectUpdated;
use Modules\Project\Models\Project;

class UpdateProjectWorkflow
{
    public function __construct(
        private readonly CalculateOperationalSettingsAction $calculateOperationalSettingsAction,
        private readonly UpdateProjectAction $updateProjectAction,
    ) {}

    public function handle(Project $project, ProjectData $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            $settings = $this->calculateOperationalSettingsAction->execute($data);

            $attributes = [
                ...$data->toArray(),
                ...$settings,
                'updated_by' => auth()->user()?->uuid,
            ];

            unset($attributes['administrative_fee_percentage']);

            if ($data->status === ProjectStatus::Archived) {
                $attributes['archived_at'] = $project->archived_at ?? now();
            } else {
                $attributes['archived_at'] = null;
            }

            $updated = $this->updateProjectAction->execute($project, $attributes);

            // Operational setting changes affect future financial operations only.
            ProjectUpdated::dispatch($updated);

            return $updated;
        });
    }
}
