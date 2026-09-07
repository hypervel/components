<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Operations\AllTag\FlushStale;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the FlushStale operation.
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
        CarbonImmutable::setTestNow('2025-06-15 12:30:45');
        $expectedTimestamp = (string) CarbonImmutable::now()->getTimestamp();

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

    public function testFlushStaleDoesNotReachCeiledScoreBeforeRequestedLifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();
        $store = $this->createStore($connection);

        $this->assertSame(1002, $store->getContext()->expirationScore(1));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1001.900000'));

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', '1001')
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once();

        (new FlushStale($store->getContext()))->execute(['_all:tag:users:entries']);
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
    public function testFlushStaleEntriesClusterModeUsesSequentialCommands(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->shouldNotReceive('pipeline');
        $connection->shouldNotReceive('multi');

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn(5);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesClusterModeWithMultipleTags(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->shouldNotReceive('pipeline');
        $connection->shouldNotReceive('multi');

        $timestamp = (string) now()->getTimestamp();
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', $timestamp)
            ->andReturn(3);
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:posts:entries', '0', $timestamp)
            ->andReturn(2);
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:comments:entries', '0', $timestamp)
            ->andReturn(0);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesClusterModeUsesCorrectPrefix(): void
    {
        [$store, , $connection] = $this->createClusterStore(prefix: 'custom_prefix:');

        $connection->shouldNotReceive('multi');

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn(1);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries']);
    }
}
