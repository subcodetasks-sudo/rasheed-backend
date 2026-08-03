<?php

namespace App\Services\Broadcast;

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

class BroadcastService
{
    /**
     * Imperative emit to one or more Socket.IO rooms.
     *
     * @param  string|list<string>  $rooms
     * @param  array<string, mixed>  $payload
     */
    public function emit(string|array $rooms, string $event, array $payload = []): void
    {
        $rooms = is_array($rooms) ? $rooms : [$rooms];
        $channels = array_map(fn (string $room) => new Channel($room), $rooms);

        $driver = config('broadcasting.default');

        if (in_array($driver, ['null', 'log'], true)) {
            Log::debug('BroadcastService emit', [
                'rooms' => $rooms,
                'event' => $event,
                'payload' => $payload,
            ]);

            if ($driver === 'null') {
                return;
            }
        }

        Broadcast::connection()->broadcast($channels, $event, $payload);
    }
}
