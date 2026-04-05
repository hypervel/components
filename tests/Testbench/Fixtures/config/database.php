<?php

declare(strict_types=1);

return [
    'redis' => [
        'fixture' => [
            'host' => 'redis-fixture-host',
            'port' => 6381,
            'database' => 9,
            'pool' => [
                'min_connections' => 2,
                'max_connections' => 4,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ],
    ],
];
