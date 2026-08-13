<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Operations\Flush;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the Flush operation.
 */
class FlushTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testFlushesCached(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('flushdb')->once()->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->flush();
        $this->assertTrue($result);
    }

    public function testFlushEnablesTransformsAndPropagatesFailure(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->expects('flushdb')->once()->andReturnFalse();
        $context = m::mock(StoreContext::class);
        $context->expects('withConnection')
            ->with(m::type('callable'), true)
            ->andReturnUsing(fn (callable $callback) => $callback($connection));

        $this->assertFalse((new Flush($context))->execute());
    }
}
