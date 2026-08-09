<?php

namespace Modules\Notifications\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Actions\MarkAllNotificationsReadAction;
use Modules\User\app\Models\User;

class MarkAllNotificationsReadController extends Controller
{
    public function __invoke(Request $request, MarkAllNotificationsReadAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $action->execute($user);

        return $this->successResponse(
            __('messages.all_notifications_marked_read_successfully'),
            $result
        );
    }
}
