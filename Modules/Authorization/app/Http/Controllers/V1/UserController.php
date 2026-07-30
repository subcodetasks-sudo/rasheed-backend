<?php

namespace Modules\Authorization\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Support\Query\BaseQueryService;
use Illuminate\Http\Request;
use Modules\Authorization\app\Http\Requests\CreateUserRequest;
use Modules\Authorization\app\Http\Requests\UpdateUserRequest;
use Modules\Authorization\app\Http\Requests\UpdateUserStatusRequest;
use Modules\Authorization\app\Services\UserService;
use Modules\User\app\Models\User;
use Modules\User\app\Transformers\UserResource;

class UserController extends Controller
{
  protected UserService $service;

  public function __construct(UserService $service)
  {
    $this->service = $service;
  }

  public function index(Request $request)
  {
    $query = User::query()
      ->with('roles')
      ->where('uuid', '!=', $request->user()->uuid);

    $perPage = $request->input('per_page', 10);

    $users = (new BaseQueryService($query, $request))
      ->allowedFilters(['status', 'email', 'roles.name'])
      ->allowedSorts(['id', 'user_name', 'created_at'])
      ->allowedSearch(['user_name', 'email'])
      ->apply()
      ->paginate($perPage);
    return $this->paginated($users, UserResource::class, __('messages.users_fetched_successfully'));
  }


  public function store(CreateUserRequest $request)
  {
    $user = $this->service->create($request->validated());
    return $this->successResponse(
      __('messages.user_created_successfully'),
      new UserResource($user)
    );
  }

  public function update(UpdateUserRequest $request, User $user)
  {
    $user = $this->service->update($user, $request->validated());
    return $this->successResponse(
      __('messages.user_updated_successfully'),
      new UserResource($user)
    );
  }

  public function destroy(Request $request, User $user)
  {
    if ($request->user()->uuid === $user->uuid) {
      return $this->forbidden(__('messages.cannot_delete_own_account'));
    }

    $this->service->delete($user);
    return $this->successResponse(__('messages.user_deleted_successfully'));
  }


  public function updateStatus(UpdateUserStatusRequest $request, User $user)
  {
    $user->status = $request->status;
    $user->save();

    return $this->successResponse(
      __('messages.user_status_updated_successfully'),
      new UserResource($user)
    );
  }
}
