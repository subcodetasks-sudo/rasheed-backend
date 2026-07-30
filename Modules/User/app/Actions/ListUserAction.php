<?php

namespace Modules\User\Actions;

use App\Support\Query\BaseQueryService;
use Modules\User\app\Models\User;

class ListUserAction
{
    public function execute($request)
    {
        $query = User::query()
            ->with('roles')
            ->where('uuid', '!=', $request->user()->uuid);

        $perPage = $request->input('per_page', 10);

        return (new BaseQueryService($query, $request))
            ->allowedFilters(['status', 'email', 'roles.name'])
            ->allowedSorts(['id', 'full_name', 'created_at', 'email'])
            ->allowedSearch(['full_name', 'email', 'user_name'])
            ->apply()
            ->paginate($perPage);
    }
}
