<?php

namespace Modules\User\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\User\app\Actions\CreateUserAction;
use Modules\User\app\Http\Requests\CreateUserRequest;
use Modules\User\app\Transformers\UserResource;

class CreateUserController extends Controller
{

  public function __invoke(CreateUserRequest $request, CreateUserAction $createUserAction): JsonResponse
  {
    $user = $createUserAction->execute($request->validated());
    return $this->successResponse(
      __('messages.user_created_successfully'),
      new UserResource($user)
    );
  }
}