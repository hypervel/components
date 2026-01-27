<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Add operation.
 *
 * Uses native Redis SET with NX (only set if Not eXists) and EX (expiration)
 * flags for atomic "add if not exists" semantics.
 *
 * @internal
 * @coversNothing
 */
class AddTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testAddReturnsTrueWhenKeyDoesNotExist(): void
    {
        $connection = $this->mockConnection();

        // SET returns true/OK when key was set
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize('bar'), ['EX' => 60, 'NX'])
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 'bar', 60);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddReturnsFalseWhenKeyExists(): void
    {
        $connection = $this->mockConnection();

        // SET with NX returns null/false when key already exists
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize('bar'), ['EX' => 60, 'NX'])
            ->andReturn(null);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 'bar', 60);
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testAddWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', 42, ['EX' => 60, 'NX'])
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 42, 60);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();

        // TTL should be at least 1
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize('bar'), ['EX' => 1, 'NX'])
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 'bar', 0);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithArrayValue(): void
    {
        $connection = $this->mockConnection();

        $value = ['key' => 'value', 'nested' => ['a', 'b']];

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize($value), ['EX' => 120, 'NX'])
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', $value, 120);
        $this->assertTrue($result);
    }
}
