<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;

class RedisProxyNonCoroutineIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    protected bool $runTestsInCoroutine = false;

    public function testTaskCleanupDiscardsAnAbandonedNativeTransaction(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_non_coroutine_multi',
            ['prefix' => ''],
        ));
        $redis->flushdb();

        $abandoned = $redis->multi();
        $this->app->make(RedisManager::class)->releaseConnections();
        $replacement = $this->nativeClient($redis);

        $this->assertNotSame($abandoned, $replacement);
        $this->assertSame(PhpRedis::ATOMIC, $replacement->getMode());
        $this->assertTrue($redis->set('after:task', 'healthy'));
        $this->assertSame('healthy', $redis->get('after:task'));
    }

    public function testTaskCleanupRestoresSelectedDatabaseBeforeReuse(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'test_non_coroutine_select',
            ['prefix' => ''],
        ));
        $redis->flushdb();
        $key = 'task:select:' . uniqid();

        $redis->select($this->getSecondaryRedisDb());
        $selected = $this->nativeClient($redis);
        $redis->set($key, 'secondary');

        $this->app->make(RedisManager::class)->releaseConnections();

        $this->assertSame($selected, $this->nativeClient($redis));
        $this->assertNull($redis->get($key));

        try {
            $redis->select($this->getSecondaryRedisDb());
            $this->assertSame('secondary', $redis->get($key));
            $redis->del($key);
        } finally {
            $this->app->make(RedisManager::class)->releaseConnections();
        }
    }

    /**
     * Get the exact native client held by a Redis proxy.
     */
    private function nativeClient(RedisProxy $redis): PhpRedis
    {
        return $redis->withConnection(function (RedisConnection $connection): PhpRedis {
            $client = $connection->client();
            $this->assertInstanceOf(PhpRedis::class, $client);

            return $client;
        });
    }
}
