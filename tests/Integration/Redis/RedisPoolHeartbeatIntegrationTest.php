<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

class RedisPoolHeartbeatIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    public function testHeartbeatCheckPingsRealRedisConnection(): void
    {
        $connectionName = 'heartbeat_success';

        $this->app->make('config')->set("database.redis.{$connectionName}", [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => $this->getParallelRedisDb(),
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => -1.0,
            ],
            'options' => ['prefix' => ''],
        ]);

        Redis::connection($connectionName)->withConnection(function (RedisConnection $connection) {
            $this->assertTrue($connection->heartbeatCheck(1.0));
        });
    }
}
