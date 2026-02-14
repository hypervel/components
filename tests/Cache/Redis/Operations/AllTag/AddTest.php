<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Add operation (intersection tags).
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
    public function testAddWithTagsReturnsTrueWhenKeyAdded(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // ZADD for tag with TTL score
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        // SET NX EX for atomic add
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithTagsReturnsFalseWhenKeyExists(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([1]);

        // SET NX returns null/false when key already exists
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn(null);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testAddWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $expectedScore = now()->timestamp + 120;

        // ZADD for each tag
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1]);

        // SET NX EX for atomic add
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 120, 'NX'])
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            120,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithEmptyTagsSkipsPipeline(): void
    {
        $connection = $this->mockConnection();

        // No pipeline operations for empty tags
        $connection->shouldNotReceive('pipeline');

        // Only SET NX EX for add
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            []
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddInClusterModeUsesSequentialCommands(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        // Sequential ZADD
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn(1);

        // SET NX EX for atomic add
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn(true);

        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddInClusterModeReturnsFalseWhenKeyExists(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Sequential ZADD (still happens even if key exists)
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn(1);

        // SET NX returns false when key exists (RedisCluster return type is string|bool)
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn(false);

        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testAddEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();

        // No pipeline for empty tags
        $connection->shouldNotReceive('pipeline');

        // TTL should be at least 1
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 1, 'NX'])
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            0,  // Zero TTL
            []
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([1]);

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', 42, ['EX' => 60, 'NX'])
            ->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            42,
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }
}
