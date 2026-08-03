<?php

namespace App\Broadcasting;

use ElephantIO\Client;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SocketIoBroadcaster extends Broadcaster
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function auth($request)
    {
        if (str_starts_with((string) $request->channel_name, 'private-')
            || str_starts_with((string) $request->channel_name, 'presence-')) {
            throw new AccessDeniedHttpException;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function validAuthenticationResponse($request, $result)
    {
        return ['success' => true];
    }

    /**
     * {@inheritdoc}
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        unset($payload['socket']);

        $rooms = array_map(
            fn ($channel) => $this->normalizeChannelName((string) $channel),
            $channels
        );

        try {
            $this->emitToServer($rooms, $event, $payload);
        } catch (\Throwable $e) {
            Log::warning('Socket.IO broadcast failed', [
                'event' => $event,
                'rooms' => $rooms,
                'message' => $e->getMessage(),
            ]);

            if (($this->config['throw'] ?? false) === true) {
                throw new BroadcastException('Socket.IO broadcast failed: '.$e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * @param  list<string>  $rooms
     * @param  array<string, mixed>  $payload
     */
    protected function emitToServer(array $rooms, string $event, array $payload): void
    {
        $url = rtrim((string) ($this->config['url'] ?? ''), '/');

        if ($url === '') {
            throw new BroadcastException('SOCKET_IO_URL is not configured.');
        }

        $options = array_merge($this->config['options'] ?? [], [
            'auth' => [
                'server_secret' => (string) ($this->config['secret'] ?? ''),
            ],
        ]);

        if (! empty($this->config['path'])) {
            $options['path'] = $this->config['path'];
        }

        $client = Client::create($url, $options);

        try {
            $client->connect();
            $client->emit('server:broadcast', [
                'rooms' => array_values($rooms),
                'event' => $event,
                'payload' => $payload,
            ]);
        } finally {
            $client->disconnect();
        }
    }

    protected function normalizeChannelName(string $channel): string
    {
        return preg_replace('/^(private|presence)-/', '', $channel) ?? $channel;
    }
}
