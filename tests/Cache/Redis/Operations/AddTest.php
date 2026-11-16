<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Add operation.
 *
 * Uses native Redis SET with NX (only set if Not eXists) and EX (expiration)
 * flags for atomic "add if not exists" semantics.
 */
class AddTest extends RedisCacheTestCase
{
    public function testAddReturnsTrueWhenKeyDoesNotExist(): void
    {
        $connection = $this->mockConnection();

        // SET returns true/OK when key was set
        $connection->client()->expects('set')
            ->with('prefix:foo', serialize('bar'), ['EX' => 60, 'NX'])
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 'bar', 60);
        $this->assertTrue($result);
    }

    public function testAddReturnsFalseWhenKeyExists(): void
    {
        $connection = $this->mockConnection();

        // Native phpredis returns false when NX finds an existing key.
        $connection->client()->expects('set')
            ->with('prefix:foo', serialize('bar'), ['EX' => 60, 'NX'])
            ->returns(false);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 'bar', 60);
        $this->assertFalse($result);
    }

    public function testAddWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        // Numeric values are NOT serialized (optimization)
        $connection->client()->expects('set')
            ->with('prefix:foo', 42, ['EX' => 60, 'NX'])
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 42, 60);
        $this->assertTrue($result);
    }

    public function testAddEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();

        // TTL should be at least 1
        $connection->client()->expects('set')
            ->with('prefix:foo', serialize('bar'), ['EX' => 1, 'NX'])
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', 'bar', 0);
        $this->assertTrue($result);
    }

    public function testAddWithArrayValue(): void
    {
        $connection = $this->mockConnection();

        $value = ['key' => 'value', 'nested' => ['a', 'b']];

        $connection->client()->expects('set')
            ->with('prefix:foo', serialize($value), ['EX' => 120, 'NX'])
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->add('foo', $value, 120);
        $this->assertTrue($result);
    }
}
