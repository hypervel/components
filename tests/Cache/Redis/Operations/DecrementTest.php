<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Decrement operation.
 */
class DecrementTest extends RedisCacheTestCase
{
    public function testDecrementMethodProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('decrby')->with('prefix:foo', 5)->returns(4);

        $redis = $this->createStore($connection);
        $result = $redis->decrement('foo', 5);
        $this->assertEquals(4, $result);
    }

    public function testDecrementOnNonExistentKeyReturnsDecrementedValue(): void
    {
        // Redis DECRBY on non-existent key initializes to 0, then decrements
        $connection = $this->mockConnection();
        $connection->client()->expects('decrby')->with('prefix:counter', 1)->returns(-1);

        $redis = $this->createStore($connection);
        $this->assertSame(-1, $redis->decrement('counter'));
    }

    public function testDecrementReturnsFalseWhenRedisRejectsTheOperation(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('decrby')->with('prefix:foo', 1)->returns(false);

        $redis = $this->createStore($connection);

        $this->assertFalse($redis->decrement('foo'));
    }
}
