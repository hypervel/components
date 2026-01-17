<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Carbon\Carbon;
use Hypervel\Cache\Redis\Operations\AllTag\FlushStale;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Mockery as m;

/**
 * Tests for the FlushStale operation.
 *
 * @internal
 * @coversNothing
 */
class FlushStaleTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testFlushStaleEntriesRemovesExpiredEntries(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($client);

        $client->shouldReceive('exec')->once();

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesWithMultipleTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // All tags should be processed in a single pipeline
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($client);
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:posts:entries', '0', (string) now()->getTimestamp())
            ->andReturn($client);
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:comments:entries', '0', (string) now()->getTimestamp())
            ->andReturn($client);

        $client->shouldReceive('exec')->once();

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
        $client = $connection->_mockClient;

        // Should NOT create pipeline or execute any commands for empty array
        $client->shouldNotReceive('pipeline');

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute([]);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesUsesCorrectPrefix(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($client);

        $client->shouldReceive('exec')->once();

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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // Lower bound is '0' (to exclude -1 forever items)
        // Upper bound is current timestamp
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', $expectedTimestamp)
            ->andReturn($client);

        $client->shouldReceive('exec')->once();

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
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // The lower bound is '0', not '-inf', so -1 scores are excluded
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', m::type('string'))
            ->andReturnUsing(function ($key, $min, $max) use ($client) {
                // Verify lower bound excludes -1 forever items
                $this->assertSame('0', $min);
                // Verify upper bound is a valid timestamp
                $this->assertIsNumeric($max);

                return $client;
            });

        $client->shouldReceive('exec')->once();

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushStaleEntriesClusterModeUsesMulti(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Cluster mode uses multi() which handles cross-slot commands
        $clusterClient->shouldReceive('multi')
            ->once()
            ->andReturn($clusterClient);

        $clusterClient->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($clusterClient);

        $clusterClient->shouldReceive('exec')
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
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Cluster mode uses multi() which handles cross-slot commands
        $clusterClient->shouldReceive('multi')
            ->once()
            ->andReturn($clusterClient);

        // All tags processed in single multi block
        $timestamp = (string) now()->getTimestamp();
        $clusterClient->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:users:entries', '0', $timestamp)
            ->andReturn($clusterClient);
        $clusterClient->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:posts:entries', '0', $timestamp)
            ->andReturn($clusterClient);
        $clusterClient->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_all:tag:comments:entries', '0', $timestamp)
            ->andReturn($clusterClient);

        $clusterClient->shouldReceive('exec')
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
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore(prefix: 'custom_prefix:');

        // Cluster mode uses multi()
        $clusterClient->shouldReceive('multi')
            ->once()
            ->andReturn($clusterClient);

        // Should use custom prefix
        $clusterClient->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->andReturn($clusterClient);

        $clusterClient->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries']);
    }
}
