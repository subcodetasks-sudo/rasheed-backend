<?php

namespace Modules\Project\Workflows\Project;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Project\Queries\ListProjectsQuery;

class ListProjectsWorkflow
{
    public function __construct(
        private readonly ListProjectsQuery $listProjectsQuery,
    ) {}

    public function handle(Request $request): LengthAwarePaginator
    {
        return $this->listProjectsQuery->paginate($request);
    }
}
