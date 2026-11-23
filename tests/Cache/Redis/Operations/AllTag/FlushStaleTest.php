<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Operations\AllTag\FlushStale;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the FlushStale operation.
 */
class FlushStaleTest extends RedisCacheTestCase
{
    public function testFlushStaleEntriesRemovesExpiredEntries(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->returns($connection->client());

        $connection->client()->expects('exec');

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushStaleEntriesWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // All tags should be processed in a single pipeline
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->returns($connection->client());
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:posts:entries', '0', (string) now()->getTimestamp())
            ->returns($connection->client());
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:comments:entries', '0', (string) now()->getTimestamp())
            ->returns($connection->client());

        $connection->client()->expects('exec');

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    public function testFlushStaleEntriesWithEmptyTagIdsReturnsEarly(): void
    {
        $connection = $this->mockConnection();

        // Should NOT create pipeline or execute any commands for empty array
        $connection->client()->expects('pipeline')->never();

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute([]);
    }

    public function testFlushStaleEntriesUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zRemRangeByScore')
            ->with('custom_prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->returns($connection->client());

        $connection->client()->expects('exec');

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushStaleEntriesUsesCurrentTimestampAsUpperBound(): void
    {
        // Set a specific time so we can verify the timestamp
        CarbonImmutable::setTestNow('2025-06-15 12:30:45');
        $expectedTimestamp = (string) CarbonImmutable::now()->getTimestamp();

        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // Lower bound is '0' (to exclude -1 forever items)
        // Upper bound is current timestamp
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:users:entries', '0', $expectedTimestamp)
            ->returns($connection->client());

        $connection->client()->expects('exec');

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

        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:users:entries', '0', '1001')
            ->returns($connection->client());
        $connection->client()->expects('exec');

        (new FlushStale($store->getContext()))->execute(['_all:tag:users:entries']);
    }

    public function testFlushStaleEntriesDoesNotRemoveForeverItems(): void
    {
        // This test documents that the score range '0' to timestamp
        // intentionally excludes items with score -1 (forever items)
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // The lower bound is '0', not '-inf', so -1 scores are excluded
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:users:entries', '0', Argument::type('string'))
            ->resolves(function ($key, $min, $max) use ($connection) {
                // Verify lower bound excludes -1 forever items
                $this->assertSame('0', $min);
                // Verify upper bound is a valid timestamp
                $this->assertIsNumeric($max);

                return $connection->client();
            });

        $connection->client()->expects('exec');

        $store = $this->createStore($connection);
        $operation = new FlushStale($store->getContext());

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushStaleEntriesClusterModeUsesSequentialCommands(): void
    {
        [$store, $client] = $this->createClusterStore();

        $client->expects('multi')->never();

        $client->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->returns(5);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushStaleEntriesClusterModeWithMultipleTags(): void
    {
        [$store, $client] = $this->createClusterStore();

        $client->expects('multi')->never();

        $timestamp = (string) now()->getTimestamp();
        $client->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:users:entries', '0', $timestamp)
            ->returns(3);
        $client->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:posts:entries', '0', $timestamp)
            ->returns(2);
        $client->expects('zRemRangeByScore')
            ->with('prefix:_all:tag:comments:entries', '0', $timestamp)
            ->returns(0);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    public function testFlushStaleEntriesClusterModeUsesCorrectPrefix(): void
    {
        [$store, $client] = $this->createClusterStore(prefix: 'custom_prefix:');

        $client->expects('multi')->never();

        $client->expects('zRemRangeByScore')
            ->with('custom_prefix:_all:tag:users:entries', '0', (string) now()->getTimestamp())
            ->returns(1);

        $operation = new FlushStale($store->getContext());
        $operation->execute(['_all:tag:users:entries']);
    }
}
