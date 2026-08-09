<?php

namespace Modules\Notifications\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Notifications\Actions\GetNotificationStatisticsAction;

class ShowNotificationStatisticsController extends Controller
{
    public function __invoke(GetNotificationStatisticsAction $action): JsonResponse
    {
        return $this->successResponse(
            __('messages.notification_statistics_fetched_successfully'),
            $action->execute()
        );
    }
}
