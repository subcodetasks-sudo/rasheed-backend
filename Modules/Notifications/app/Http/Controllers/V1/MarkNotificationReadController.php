<?php

namespace Modules\Notifications\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Actions\MarkNotificationReadAction;
use Modules\Notifications\Http\Resources\NotificationResource;
use Modules\Notifications\Models\Notification;
use Modules\User\app\Models\User;

class MarkNotificationReadController extends Controller
{
    public function __invoke(
        Request $request,
        Notification $notification,
        MarkNotificationReadAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $marked = $action->execute($notification, $user);

        return $this->successResponse(
            __('messages.notification_marked_read_successfully'),
            new NotificationResource($marked)
        );
    }
}
