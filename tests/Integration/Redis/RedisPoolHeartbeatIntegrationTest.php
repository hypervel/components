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
        $connectionName = $this->createRedisConnectionWithOptions(
            'heartbeat_success',
            ['prefix' => ''],
            maxConnections: 1,
        );

        Redis::connection($connectionName)->withConnection(function (RedisConnection $connection) {
            $this->assertTrue($connection->heartbeatCheck(1.0));
        });
    }
}
