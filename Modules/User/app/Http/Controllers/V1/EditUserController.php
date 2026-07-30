<?php

namespace Modules\User\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\User\app\Actions\EditUserAction;
use Modules\User\app\Http\Requests\UpdateUserRequest;
use Modules\User\app\Models\User;
use Modules\User\app\Transformers\UserResource;

class EditUserController extends Controller
{
  public function __invoke(UpdateUserRequest $request, User $user, EditUserAction $editUserAction): JsonResponse
  {
    // dd($request->full_name);
    $user = $editUserAction->execute($user, $request->validated());
    return $this->successResponse(
      __('messages.user_updated_successfully'),
      new UserResource($user)
    );
  }
}
