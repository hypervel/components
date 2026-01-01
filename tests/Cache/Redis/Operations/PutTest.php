<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;

/**
 * Tests for the Put operation.
 *
 * @internal
 * @coversNothing
 */
class PutTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testSetMethodProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, serialize('foo'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->put('foo', 'foo', 60);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testSetMethodProperlyCallsRedisForNumerics(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, 1)
            ->andReturn(false);

        $redis = $this->createStore($connection);
        $result = $redis->put('foo', 1, 60);
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testPutPreservesArrayValues(): void
    {
        $connection = $this->mockConnection();
        $array = ['nested' => ['data' => 'value']];
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 60, serialize($array))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->put('foo', $array, 60));
    }

    /**
     * @test
     */
    public function testPutEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();
        // TTL of 0 should become 1 (Redis requires positive TTL for SETEX)
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:foo', 1, serialize('bar'))
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->put('foo', 'bar', 0));
    }
}
