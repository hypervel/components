<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Increment operation.
 *
 * @internal
 * @coversNothing
 */
class IncrementTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testIncrementMethodProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('incrby')->once()->with('prefix:foo', 5)->andReturn(6);

        $redis = $this->createStore($connection);
        $result = $redis->increment('foo', 5);
        $this->assertEquals(6, $result);
    }

    /**
     * @test
     */
    public function testIncrementOnNonExistentKeyReturnsIncrementedValue(): void
    {
        // Redis INCRBY on non-existent key initializes to 0, then increments
        $connection = $this->mockConnection();
        $connection->shouldReceive('incrby')->once()->with('prefix:counter', 1)->andReturn(1);

        $redis = $this->createStore($connection);
        $this->assertSame(1, $redis->increment('counter'));
    }

    /**
     * @test
     */
    public function testIncrementWithLargeValue(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('incrby')->once()->with('prefix:foo', 1000000)->andReturn(1000005);

        $redis = $this->createStore($connection);
        $this->assertSame(1000005, $redis->increment('foo', 1000000));
    }
}
