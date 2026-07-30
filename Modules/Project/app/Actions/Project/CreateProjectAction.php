<?php

namespace Modules\Project\Actions\Project;

use Modules\Project\Models\Project;

class CreateProjectAction
{
    public function execute(array $attributes): Project
    {
        return Project::create($attributes)->load(['category', 'creator', 'updater']);
    }
}
