<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Carbon\Carbon;
use Hypervel\Cache\Redis\Operations\AllTag\AddEntry;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the AddEntry operation.
 *
 * @internal
 * @coversNothing
 */
class AddEntryTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testAddEntryWithTtl(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 300, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 300, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithZeroTtlStoresNegativeOne(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithNegativeTtlStoresNegativeOne(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', -5, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithUpdateWhenNxCondition(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'NX');
    }

    /**
     * @test
     */
    public function testAddEntryWithUpdateWhenXxCondition(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['XX'], -1, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'XX');
    }

    /**
     * @test
     */
    public function testAddEntryWithUpdateWhenGtCondition(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['GT'], now()->timestamp + 60, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, ['_all:tag:users:entries'], 'GT');
    }

    /**
     * @test
     */
    public function testAddEntryWithMultipleTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn($client);
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', now()->timestamp + 60, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, ['_all:tag:users:entries', '_all:tag:posts:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithEmptyTagsArrayDoesNothing(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // No pipeline or zadd calls should be made
        $client->shouldNotReceive('pipeline');
        $client->shouldNotReceive('zadd');

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, []);
    }

    /**
     * @test
     */
    public function testAddEntryUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeUsesSequentialCommands(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Should use sequential zadd calls directly on client
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 300, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 300, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeWithMultipleTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Should use sequential zadd calls for each tag
        $expectedScore = now()->timestamp + 60;
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->andReturn(1);
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->andReturn(1);
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:comments:entries', $expectedScore, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 60, ['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeWithUpdateWhenFlag(): void
    {
        [$store, $clusterClient] = $this->createClusterStore();

        // Should use zadd with NX flag as array (phpredis requires array for options)
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'NX');
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeWithZeroTtlStoresNegativeOne(): void
    {
        [$store, $clusterClient] = $this->createClusterStore();

        // Score should be -1 for forever items (TTL = 0)
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }
}
