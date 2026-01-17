<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Decrement operation.
 *
 * @internal
 * @coversNothing
 */
class DecrementTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testDecrementMethodProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('decrby')->once()->with('prefix:foo', 5)->andReturn(4);

        $redis = $this->createStore($connection);
        $result = $redis->decrement('foo', 5);
        $this->assertEquals(4, $result);
    }

    /**
     * @test
     */
    public function testDecrementOnNonExistentKeyReturnsDecrementedValue(): void
    {
        // Redis DECRBY on non-existent key initializes to 0, then decrements
        $connection = $this->mockConnection();
        $connection->shouldReceive('decrby')->once()->with('prefix:counter', 1)->andReturn(-1);

        $redis = $this->createStore($connection);
        $this->assertSame(-1, $redis->decrement('counter'));
    }
}
