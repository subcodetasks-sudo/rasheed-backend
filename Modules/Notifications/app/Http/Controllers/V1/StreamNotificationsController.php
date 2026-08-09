<?php

namespace Modules\Notifications\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Services\NotificationSseService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamNotificationsController extends Controller
{
    public function __invoke(Request $request, NotificationSseService $sse): StreamedResponse|JsonResponse
    {
        // Axios / JSON clients send Accept: application/json and await a finished body.
        // That keeps the request Pending and blocks page load (and can stall a single PHP worker).
        if ($this->expectsJsonWithoutSse($request)) {
            return response()->json([
                'success' => false,
                'message' => __(
                    'messages.notification_stream_requires_sse_client'
                ),
                'data' => null,
            ], 406);
        }

        return $sse->stream($request);
    }

    protected function expectsJsonWithoutSse(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));

        if ($accept === '' || str_contains($accept, 'text/event-stream')) {
            return false;
        }

        return str_contains($accept, 'application/json');
    }
}
