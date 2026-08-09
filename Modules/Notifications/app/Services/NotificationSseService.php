<?php

namespace Modules\Notifications\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Http\Resources\NotificationResource;
use Modules\Notifications\Models\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class NotificationSseService
{
    public const LATEST_ID_CACHE_KEY = 'notifications:sse:latest_id';

    public function stream(Request $request): StreamedResponse
    {
        $pollSeconds = max(0, (int) config('notifications.sse.poll_seconds', 1));
        $heartbeatSeconds = max(1, (int) config('notifications.sse.heartbeat_seconds', 10));
        $maxDurationSeconds = max(1, (int) config('notifications.sse.max_duration_seconds', 25));
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
            $this->writeEvent('stream.ready', '0', [
                'cursor' => $lastId,
                'message' => 'SSE connected. New notifications with id > cursor will be pushed.',
            ]);
            $this->writeHeartbeat();

            try {
                while (true) {
                    if ((time() - $startedAt) >= $maxDurationSeconds) {
                        break;
                    }

                    $limit = $replayed ? $batchLimit : $replayLimit;

                    $notifications = $this->fetchNewerThan($lastId, $limit);

                    foreach ($notifications as $notification) {
                        $payload = (new NotificationResource($notification))
                            ->resolve(request());

                        $written = $this->writeEvent(
                            'notification.created',
                            (string) $notification->id,
                            $payload
                        );

                        if (! $written) {
                            return;
                        }

                        $lastId = (int) $notification->id;
                    }

                    $replayed = true;

                    $now = time();
                    if (($now - $lastHeartbeatAt) >= $heartbeatSeconds) {
                        if (! $this->writeHeartbeat()) {
                            return;
                        }
                        $lastHeartbeatAt = $now;
                    }

                    if (! $this->waitForNewerOrTimeout($lastId, $pollSeconds, $startedAt, $maxDurationSeconds)) {
                        break;
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

    /**
     * Called after a notification row is persisted so open streams wake quickly.
     */
    public static function announce(int $notificationId): void
    {
        Cache::put(self::LATEST_ID_CACHE_KEY, $notificationId, now()->addDay());
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
     * @return Collection<int, Notification>
     */
    protected function fetchNewerThan(int $lastId, int $limit)
    {
        if (! app()->runningUnitTests() && DB::getDriverName() !== 'sqlite') {
            try {
                DB::reconnect();
            } catch (Throwable) {
                // Keep going with the existing connection if reconnect fails.
            }
        }

        return Notification::query()
            ->with('project')
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    protected function hasNewerThan(int $lastId): bool
    {
        $cachedLatest = (int) Cache::get(self::LATEST_ID_CACHE_KEY, 0);

        if ($cachedLatest > $lastId) {
            return true;
        }

        try {
            return Notification::query()->where('id', '>', $lastId)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Sleep in short slices so new rows are picked up quickly and the stream can end on schedule.
     */
    protected function waitForNewerOrTimeout(
        int $lastId,
        int $pollSeconds,
        int $startedAt,
        int $maxDurationSeconds,
    ): bool {
        if ($pollSeconds <= 0) {
            usleep(100_000);

            return (time() - $startedAt) < $maxDurationSeconds;
        }

        $deadline = min(time() + $pollSeconds, $startedAt + $maxDurationSeconds);

        while (time() < $deadline) {
            if ($this->hasNewerThan($lastId)) {
                return true;
            }

            usleep(200_000);
        }

        return (time() - $startedAt) < $maxDurationSeconds;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeEvent(string $event, string $id, array $payload): bool
    {
        echo 'event: '.$event."\n";
        echo 'id: '.$id."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

        return $this->flushOutput();
    }

    protected function writeHeartbeat(): bool
    {
        echo ": heartbeat\n\n";

        return $this->flushOutput();
    }

    protected function writeRetry(int $milliseconds): bool
    {
        echo 'retry: '.$milliseconds."\n\n";

        return $this->flushOutput();
    }

    protected function flushOutput(): bool
    {
        if (function_exists('ob_flush') && ob_get_level() > 0) {
            @ob_flush();
        }

        if (function_exists('flush')) {
            @flush();
        }

        // connection_aborted() is unreliable on some Windows SAPIs; only stop after a write attempt.
        return ! connection_aborted();
    }
}
