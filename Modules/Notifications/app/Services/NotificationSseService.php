<?php

namespace Modules\Notifications\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Http\Resources\NotificationResource;
use Modules\Notifications\Models\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class NotificationSseService
{
    public function stream(Request $request): StreamedResponse
    {
        $pollSeconds = max(0, (int) config('notifications.sse.poll_seconds', 2));
        $heartbeatSeconds = max(1, (int) config('notifications.sse.heartbeat_seconds', 15));
        $maxDurationSeconds = max(1, (int) config('notifications.sse.max_duration_seconds', 300));
        $replayLimit = max(1, (int) config('notifications.sse.replay_limit', 100));
        $batchLimit = max(1, (int) config('notifications.sse.batch_limit', 50));
        $retryMilliseconds = max(1000, (int) config('notifications.sse.retry_milliseconds', 3000));

        $cursor = $this->resolveCursor($request);

        // Release the session lock before the long-lived stream so concurrent API calls are not blocked.
        $this->releaseSessionLock();

        return response()->stream(function () use (
            $cursor,
            $pollSeconds,
            $heartbeatSeconds,
            $maxDurationSeconds,
            $replayLimit,
            $batchLimit,
            $retryMilliseconds,
        ): void {
            $this->prepareStream($maxDurationSeconds);

            $startedAt = time();
            $lastHeartbeatAt = $startedAt;
            $lastId = $cursor;
            $replayed = false;

            $this->writeRetry($retryMilliseconds);
            $this->writeHeartbeat();

            try {
                while (true) {
                    if (connection_aborted()) {
                        break;
                    }

                    if ((time() - $startedAt) >= $maxDurationSeconds) {
                        break;
                    }

                    $limit = $replayed ? $batchLimit : $replayLimit;

                    $notifications = Notification::query()
                        ->with('project')
                        ->where('id', '>', $lastId)
                        ->orderBy('id')
                        ->limit($limit)
                        ->get();

                    foreach ($notifications as $notification) {
                        if (connection_aborted()) {
                            return;
                        }

                        $payload = (new NotificationResource($notification))
                            ->resolve(request());

                        $this->writeEvent(
                            'notification.created',
                            (string) $notification->id,
                            $payload
                        );

                        $lastId = (int) $notification->id;
                    }

                    $replayed = true;

                    $now = time();
                    if (($now - $lastHeartbeatAt) >= $heartbeatSeconds) {
                        $this->writeHeartbeat();
                        $lastHeartbeatAt = $now;
                    }

                    if ($pollSeconds > 0) {
                        sleep($pollSeconds);
                    } else {
                        usleep(100_000);
                    }
                }
            } catch (Throwable $e) {
                Log::error('Notification SSE stream failed.', [
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function resolveCursor(Request $request): int
    {
        $raw = $request->headers->get('Last-Event-ID', $request->query('last_event_id'));

        if ($raw !== null && $raw !== '' && is_numeric($raw) && (int) $raw >= 0) {
            return (int) $raw;
        }

        return (int) (Notification::query()->max('id') ?? 0);
    }

    protected function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    protected function prepareStream(int $maxDurationSeconds): void
    {
        $this->releaseSessionLock();

        // Allow the stream to run for its configured window; detect disconnect via connection_aborted().
        @set_time_limit($maxDurationSeconds + 30);
        @ignore_user_abort(true);

        // PHPUnit captures StreamedResponse via its own output buffers; do not tear them down.
        if (app()->runningUnitTests()) {
            return;
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        @ob_implicit_flush(true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeEvent(string $event, string $id, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'id: '.$id."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

        $this->flushOutput();
    }

    protected function writeHeartbeat(): void
    {
        echo ": heartbeat\n\n";

        $this->flushOutput();
    }

    protected function writeRetry(int $milliseconds): void
    {
        echo 'retry: '.$milliseconds."\n\n";

        $this->flushOutput();
    }

    protected function flushOutput(): void
    {
        if (function_exists('flush')) {
            @flush();
        }
    }
}
