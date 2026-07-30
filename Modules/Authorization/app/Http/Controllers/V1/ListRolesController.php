<?php

namespace Modules\Authorization\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Authorization\app\Http\Resources\RoleResource;
use Spatie\Permission\Models\Role;

class ListRolesController extends Controller
{
  public function __invoke(): JsonResponse
  {
    $roles = Role::all();
    return $this->successResponse(
      __('messages.roles_fetched_successfully'),
      RoleResource::collection($roles)
    );
  }
}