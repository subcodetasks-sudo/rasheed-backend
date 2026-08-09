<?php

namespace Modules\Notifications\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Actions\ShowNotificationAction;
use Modules\Notifications\Http\Resources\NotificationResource;
use Modules\Notifications\Models\Notification;
use Modules\User\app\Models\User;

class ShowNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        Notification $notification,
        ShowNotificationAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $shown = $action->execute($notification, $user);

        return $this->successResponse(
            __('messages.notification_fetched_successfully'),
            new NotificationResource($shown)
        );
    }
}
