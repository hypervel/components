<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Carbon\Carbon;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the Put operation (intersection tags).
 *
 * @internal
 * @coversNothing
 */
class PutTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testPutStoresValueWithTagsInPipelineMode(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // ZADD for tag
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn($client);

        // SETEX for cache value
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
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
    public function testPutWithMultipleTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $expectedScore = now()->timestamp + 120;

        // ZADD for each tag
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->andReturn($client);
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->andReturn($client);

        // SETEX for cache value
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 120, serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
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
    public function testPutWithEmptyTagsStillStoresValue(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // No ZADD calls expected
        // SETEX for cache value
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
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
    public function testPutUsesCorrectPrefix(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')
            ->once()
            ->with('custom:_all:tag:users:entries', now()->timestamp + 30, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('setex')
            ->once()
            ->with('custom:mykey', 30, serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection, 'custom:');
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            30,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);
        $client->shouldReceive('setex')->andReturn($client);

        // SETEX returns false (failure)
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, false]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
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
    public function testPutInClusterModeUsesSequentialCommands(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Sequential ZADD
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn(1);

        // Sequential SETEX
        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->andReturn(true);

        $result = $store->allTagOps()->put()->execute(
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
    public function testPutEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);

        // TTL should be at least 1
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 1, serialize('myvalue'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            0,  // Zero TTL
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutWithNumericValue(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);

        // Numeric values are NOT serialized (optimization)
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, 42)
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            42,
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }
}
