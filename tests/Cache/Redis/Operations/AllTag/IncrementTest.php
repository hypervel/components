<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the Increment operation (intersection tags).
 *
 * @internal
 * @coversNothing
 */
class IncrementTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testIncrementWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // ZADD NX for tag with score -1 (only add if not exists)
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn($client);

        // INCRBY
        $client->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 1)
            ->andReturn($client);

        $client->shouldReceive('exec')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn($client);

        $client->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 10)
            ->andReturn($client);

        $client->shouldReceive('exec')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // ZADD NX for each tag
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn($client);
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', ['NX'], -1, 'counter')
            ->andReturn($client);

        $client->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 1)
            ->andReturn($client);

        $client->shouldReceive('exec')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // No ZADD calls expected
        $client->shouldReceive('incrby')
            ->once()
            ->with('prefix:counter', 1)
            ->andReturn($client);

        $client->shouldReceive('exec')
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
        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Sequential ZADD NX
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn(1);

        // Sequential INCRBY
        $clusterClient->shouldReceive('incrby')
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
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);
        $client->shouldReceive('incrby')->andReturn($client);

        $client->shouldReceive('exec')
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
