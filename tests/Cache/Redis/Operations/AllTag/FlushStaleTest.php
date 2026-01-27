<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Carbon\Carbon;
use Hypervel\Cache\Redis\Operations\AllTag\FlushStale;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the FlushStale operation.
 *
 * @internal
 * @coversNothing
 */
class FlushStaleTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testFlushStaleEntriesRemovesExpiredEntries(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($connection);

        $connection->shouldReceive('exec')->once();

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // All tags should be processed in a single pipeline
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($connection);
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:posts:entries', '0', (string) now()->getTimestamp())
            ->andReturn($connection);
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:comments:entries', '0', (string) now()->getTimestamp())
            ->andReturn($connection);

        $connection->shouldReceive('exec')->once();

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesWithEmptyTagIdsReturnsEarly(): void
    {
        $connection = $this->mockConnection();

        // Should NOT create pipeline or execute any commands for empty array
        $connection->shouldNotReceive('pipeline');

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute([]);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($connection);

        $connection->shouldReceive('exec')->once();

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesUsesCurrentTimestampAsUpperBound(): void
    {
        // Set a specific time so we can verify the timestamp
        Carbon::setTestNow('2025-06-15 12:30:45');
        $expectedTimestamp = (string) Carbon::now()->getTimestamp();

        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // Lower bound is '0' (to exclude -1 forever items)
        // Upper bound is current timestamp
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', $expectedTimestamp)
            ->andReturn($connection);

        $connection->shouldReceive('exec')->once();

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesDoesNotRemoveForeverItems(): void
    {
        // This test documents that the score range '0' to timestamp
        // intentionally excludes items with score -1 (forever items)
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // The lower bound is '0', not '-inf', so -1 scores are excluded
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', m::type('string'))
            ->andReturnUsing(function ($key, $min, $max) use ($connection) {
                // Verify lower bound excludes -1 forever items
                $this->assertSame('0', $min);
                // Verify upper bound is a valid timestamp
                $this->assertIsNumeric($max);

                return $connection;
            });

        $connection->shouldReceive('exec')->once();

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesClusterModeUsesMulti(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        // Cluster mode uses multi() which handles cross-slot commands
        $connection->shouldReceive('multi')
            ->once()
            ->andReturn($connection);

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([5]);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesClusterModeWithMultipleTags(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        // Cluster mode uses multi() which handles cross-slot commands
        $connection->shouldReceive('multi')
            ->once()
            ->andReturn($connection);

        // All tags processed in single multi block
        $timestamp = (string) now()->getTimestamp();
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', $timestamp)
            ->andReturn($connection);
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:posts:entries', '0', $timestamp)
            ->andReturn($connection);
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:comments:entries', '0', $timestamp)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([3, 2, 0]);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesClusterModeUsesCorrectPrefix(): void
    {
        [$store, , $connection] = $this->createClusterStore(prefix: 'custom_prefix:');

        // Cluster mode uses multi()
        $connection->shouldReceive('multi')
            ->once()
            ->andReturn($connection);

        // Should use custom prefix
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries']);
    }
}
