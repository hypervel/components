<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for the Forever operation (intersection tags).
 *
 * @internal
 * @coversNothing
 */
class ForeverTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testForeverStoresValueWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // ZADD for tag with score -1 (forever)
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($client);

        // SET for cache value (no expiration)
        $client->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testForeverWithMultipleTags(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // ZADD for each tag with score -1
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($client);
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', -1, 'mykey')
            ->andReturn($client);

        // SET for cache value
        $client->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testForeverWithEmptyTags(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // SET for cache value only
        $client->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            []
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testForeverInClusterModeUsesSequentialCommands(): void
    {
        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Sequential ZADD with score -1
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn(1);

        // Sequential SET
        $clusterClient->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn(true);

        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testForeverReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);
        $client->shouldReceive('set')->andReturn($client);

        // SET returns false (failure)
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, false]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testForeverUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('custom:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('set')
            ->once()
            ->with('custom:mykey', serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection, 'custom:');
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);

        // Numeric values are NOT serialized (optimization)
        $client->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', 42)
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            42,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }
}
