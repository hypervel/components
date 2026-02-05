<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Increment operation (intersection tags).
 *
 * @internal
 * @coversNothing
 */
class IncrementTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testIncrementWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // ZADD NX for tag with score -1 (only add if not exists)
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn($connection);

        // INCRBY
        $connection->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 1)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1, 5]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(5, $result);
    }

    /**
     * @test
     */
    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn($connection);

        $connection->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 10)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([0, 15]);  // 0 means key already existed (NX condition)

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            10,
            ['_all:tag:users:entries']
        );

        $this->assertSame(15, $result);
    }

    /**
     * @test
     */
    public function testIncrementWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // ZADD NX for each tag
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', ['NX'], -1, 'counter')
            ->andReturn($connection);

        $connection->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 1)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertSame(1, $result);
    }

    /**
     * @test
     */
    public function testIncrementWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // No ZADD calls expected
        $connection->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 1)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            []
        );

        $this->assertSame(1, $result);
    }

    /**
     * @test
     */
    public function testIncrementInClusterModeUsesSequentialCommands(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        // Sequential ZADD NX
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn(1);

        // Sequential INCRBY
        $connection->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 1)
            ->andReturn(10);

        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(10, $result);
    }

    /**
     * @test
     */
    public function testIncrementReturnsFalseOnPipelineFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('incrby')->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn(false);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }
}
