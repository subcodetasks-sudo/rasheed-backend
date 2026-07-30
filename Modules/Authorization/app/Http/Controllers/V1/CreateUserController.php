<?php

namespace Modules\Authorization\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Authorization\app\Actions\CreateUserAction;
use Modules\Authorization\app\Http\Requests\CreateUserRequest;
use Modules\User\app\Transformers\UserResource;

class CreateUserController extends Controller
{
  public function __construct(private CreateUserAction $createUserAction) {}

  public function __invoke(CreateUserRequest $request): JsonResponse
  {
    $user = $this->createUserAction->execute($request->validated());
    return $this->successResponse(
      __('messages.user_created_successfully'),
      new UserResource($user)
    );
  }
}
