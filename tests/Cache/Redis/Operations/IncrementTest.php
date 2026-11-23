<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Increment operation.
 */
class IncrementTest extends RedisCacheTestCase
{
    public function testIncrementMethodProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('incrby')->with('prefix:foo', 5)->returns(6);

        $redis = $this->createStore($connection);
        $result = $redis->increment('foo', 5);
        $this->assertEquals(6, $result);
    }

    public function testIncrementOnNonExistentKeyReturnsIncrementedValue(): void
    {
        // Redis INCRBY on non-existent key initializes to 0, then increments
        $connection = $this->mockConnection();
        $connection->client()->expects('incrby')->with('prefix:counter', 1)->returns(1);

        $redis = $this->createStore($connection);
        $this->assertSame(1, $redis->increment('counter'));
    }

    public function testIncrementWithLargeValue(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('incrby')->with('prefix:foo', 1000000)->returns(1000005);

        $redis = $this->createStore($connection);
        $this->assertSame(1000005, $redis->increment('foo', 1000000));
    }

    public function testIncrementReturnsFalseWhenRedisRejectsTheOperation(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('incrby')->with('prefix:foo', 1)->returns(false);

        $redis = $this->createStore($connection);

        $this->assertFalse($redis->increment('foo'));
    }
}
