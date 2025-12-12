<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Carbon\Carbon;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;

/**
 * Tests for the Add operation (intersection tags).
 *
 * Uses native Redis SET with NX (only set if Not eXists) and EX (expiration)
 * flags for atomic "add if not exists" semantics.
 *
 * @internal
 * @coversNothing
 */
class AddTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testAddWithTagsReturnsTrueWhenKeyAdded(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // ZADD for tag with TTL score
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        // SET NX EX for atomic add
        $client->shouldReceive('set')
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
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([1]);

        // SET NX returns null/false when key already exists
        $client->shouldReceive('set')
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

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1]);

        // SET NX EX for atomic add
        $client->shouldReceive('set')
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
        $client = $connection->_mockClient;

        // No pipeline operations for empty tags
        $client->shouldNotReceive('pipeline');

        // Only SET NX EX for add
        $client->shouldReceive('set')
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
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        // Sequential ZADD
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn(1);

        // SET NX EX for atomic add
        $clusterClient->shouldReceive('set')
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
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Sequential ZADD (still happens even if key exists)
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn(1);

        // SET NX returns false when key exists (RedisCluster return type is string|bool)
        $clusterClient->shouldReceive('set')
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
        $client = $connection->_mockClient;

        // No pipeline for empty tags
        $client->shouldNotReceive('pipeline');

        // TTL should be at least 1
        $client->shouldReceive('set')
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
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([1]);

        // Numeric values are NOT serialized (optimization)
        $client->shouldReceive('set')
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
