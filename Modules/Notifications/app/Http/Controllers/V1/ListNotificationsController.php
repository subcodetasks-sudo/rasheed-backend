<?php

namespace Modules\Notifications\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Actions\ListNotificationsAction;
use Modules\Notifications\Http\Resources\NotificationResource;

class ListNotificationsController extends Controller
{
    public function __invoke(Request $request, ListNotificationsAction $action): JsonResponse
    {
        $notifications = $action->execute($request);

        return $this->paginated(
            $notifications,
            NotificationResource::class,
            __('messages.notifications_fetched_successfully')
        );
    }
}
