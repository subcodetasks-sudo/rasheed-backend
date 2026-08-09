<?php

return [
    'name' => 'Notifications',

    'sse' => [
        'poll_seconds' => (int) env('NOTIFICATIONS_SSE_POLL_SECONDS', 1),
        'heartbeat_seconds' => (int) env('NOTIFICATIONS_SSE_HEARTBEAT_SECONDS', 10),
        'max_duration_seconds' => (int) env('NOTIFICATIONS_SSE_MAX_DURATION_SECONDS', 25),
        'replay_limit' => (int) env('NOTIFICATIONS_SSE_REPLAY_LIMIT', 100),
        'batch_limit' => (int) env('NOTIFICATIONS_SSE_BATCH_LIMIT', 50),
        'retry_milliseconds' => (int) env('NOTIFICATIONS_SSE_RETRY_MILLISECONDS', 3000),
    ],
];
