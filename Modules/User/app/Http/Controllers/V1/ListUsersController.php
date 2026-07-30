<?php

namespace Modules\User\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Support\Query\BaseQueryService;
use Illuminate\Http\Request;
use Modules\User\app\Models\User;
use Modules\User\app\Transformers\UserResource;

class ListUsersController extends Controller
{

  public function __invoke(Request $request)
  {
    $query = User::query()
        ->with('roles')
        ->where('uuid', '!=', $request->user()->uuid);

    $perPage = $request->input('per_page', 10);

    $users = (new BaseQueryService($query, $request))
        ->allowedFilters(['status', 'email', 'roles.name'])
        ->allowedSorts(['id', 'full_name', 'created_at', 'email'])
        ->allowedSearch(['full_name', 'email', 'user_name'])
        ->apply()
        ->paginate($perPage);

    return $this->paginated($users, UserResource::class, __('messages.users_fetched_successfully'));
  }
}
