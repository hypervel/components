<?php

declare(strict_types=1);

use Hypervel\Support\Env;
use Hypervel\Testbench\Foundation\Env as TestbenchEnv;
use Hypervel\Tests\PHPStan\Rules\Data\CachedEnv;

$delay = env('QUEUE_DELAY', 10);
$retries = (bool) env('RETRIES_ENABLED', true);
$defaultPort = (int) env('DEFAULT_PORT', 5432);

return [
    'port' => env('DB_PORT', 3306),
    'debug' => Env::get('APP_DEBUG', false),
    'ratio' => env('RATIO', 1.5) ?: 2.0,
    'timeout' => env('TIMEOUT') ?? env('DEFAULT_TIMEOUT', 30),
    'retries' => $retries ? env('RETRIES', 3) : 0,
    'servers' => [env('SERVER_WEIGHT', -1)],
    'cast' => (int) env('CAST', 3306),
    'filtered' => filter_var(env('FILTERED', false), FILTER_VALIDATE_BOOL),
    'scheme' => env('REDIS_TLS', false) ? 'tls' : 'tcp',
    'nullable' => ($ttl = env('TTL', 60)) === null ? null : (int) $ttl,
    'delay' => $delay === null ? null : (int) $delay,
    'name' => env('APP_NAME', 'Hypervel'),
    'host' => env('DB_HOST'),
    'idle' => env('CACHE_IDLE', env('IDLE', 60)),
    'replica_port' => env('REPLICA_PORT', $defaultPort),
    'assigned' => ($assignedPort = env('ASSIGNED_PORT', 8080)),
    'testbench' => TestbenchEnv::get('TESTBENCH_PORT', 8081),
    'cached' => CachedEnv::get('CACHED_PORT', 8082),
];
