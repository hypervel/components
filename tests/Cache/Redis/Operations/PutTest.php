<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Put operation.
 */
class PutTest extends RedisCacheTestCase
{
    public function testSetMethodProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('setex')
            ->with('prefix:foo', 60, serialize('foo'))
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->put('foo', 'foo', 60);
        $this->assertTrue($result);
    }

    public function testSetMethodProperlyCallsRedisForNumerics(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('setex')
            ->with('prefix:foo', 60, 1)
            ->returns(false);

        $redis = $this->createStore($connection);
        $result = $redis->put('foo', 1, 60);
        $this->assertFalse($result);
    }

    public function testPutPreservesArrayValues(): void
    {
        $connection = $this->mockConnection();
        $array = ['nested' => ['data' => 'value']];
        $connection->client()->expects('setex')
            ->with('prefix:foo', 60, serialize($array))
            ->returns(true);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->put('foo', $array, 60));
    }

    public function testPutEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();
        // TTL of 0 should become 1 (Redis requires positive TTL for SETEX)
        $connection->client()->expects('setex')
            ->with('prefix:foo', 1, serialize('bar'))
            ->returns(true);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->put('foo', 'bar', 0));
    }
}
