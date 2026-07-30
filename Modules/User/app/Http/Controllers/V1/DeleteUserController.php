<?php

namespace Modules\User\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\app\Actions\DeleteUserAction;
use Modules\User\app\Models\User;

class DeleteUserController extends Controller
{
  public function __invoke(Request $request, User $user, DeleteUserAction $deleteUserAction): JsonResponse
  {
    if ($request->user()->uuid === $user->uuid) {
      return $this->forbidden(__('messages.cannot_delete_own_account'));
    }

    $deleteUserAction->execute($user);
    return $this->successResponse(__('messages.user_deleted_successfully'));
  }
}
