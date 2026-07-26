<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Context\CoroutineContext;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class RedisProxyNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected function tearDown(): void
    {
        CoroutineContext::forget(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default');

        parent::tearDown();
    }

    public function testMultiPinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('multi', [], new stdClass);
    }

    public function testPipelinePinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('pipeline', [], new stdClass);
    }

    public function testSelectPinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('select', [2], true, 2);
    }

    public function testWatchPinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('watch', ['key'], true);
    }

    /**
     * Assert a successful stateful command pins and terminally releases its wrapper.
     */
    private function assertCommandPinsConnection(
        string $command,
        array $arguments,
        mixed $result,
        ?int $selectedDatabase = null,
    ): void {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('shouldTransform')->andReturnSelf();
        $connection->shouldReceive('getConnection')->andReturnSelf();
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->expects($command)->with(...$arguments)->andReturn($result);
        $connection->expects('release');

        if ($selectedDatabase !== null) {
            $connection->expects('setDatabase')->with($selectedDatabase);
        }

        $pool = m::mock(RedisPool::class);
        $pool->expects('get')->andReturn($connection);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);
        $redis = new RedisProxy(
            $factory,
            'default',
            m::mock(RedisSentinelFactory::class),
        );

        $this->assertSame($result, $redis->{$command}(...$arguments));
        $this->assertSame(
            $connection,
            CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default')
        );

        $redis->releaseContextConnection();

        $this->assertFalse(
            CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default')
        );
    }
}
