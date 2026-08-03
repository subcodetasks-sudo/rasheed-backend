<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'socketio' => [
            'driver' => 'socketio',
            'url' => env('SOCKET_IO_URL', 'http://127.0.0.1:3001'),
            'path' => env('SOCKET_IO_PATH', '/socket.io'),
            'secret' => env('SOCKET_IO_SECRET', ''),
            'throw' => false,
            'options' => [
                'client' => \ElephantIO\Client::CLIENT_4X,
                'transport' => 'polling',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
