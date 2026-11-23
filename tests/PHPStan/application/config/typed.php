<?php

declare(strict_types=1);

return [
    'name' => 'Hypervel',
    'label' => 'Typed',
    'port' => 3306,
    'ratio' => 1.5,
    'debug' => (bool) env('APP_DEBUG', false),
    'hosts' => ['primary', 'replica'],
    'either' => PHP_OS_FAMILY === 'Windows' ? 3306 : '/tmp/mysql.sock',
    'nullable' => null,
];
