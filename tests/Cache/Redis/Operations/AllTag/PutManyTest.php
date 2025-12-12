<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Carbon\Carbon;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for the PutMany operation (intersection tags).
 *
 * @internal
 * @coversNothing
 */
class PutManyTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testPutManyWithTagsInPipelineMode(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $expectedScore = now()->timestamp + 60;

        // Variadic ZADD: one command with all members for the tag
        // Format: key, score1, member1, score2, member2, ...
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:baz')
            ->andReturn($client);

        // SETEX for each key
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->andReturn($client);

        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:baz', 60, serialize('qux'))
            ->andReturn($client);

        // Results: 1 ZADD (returns count of new members) + 2 SETEX (return true)
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([2, true, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar', 'baz' => 'qux'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithMultipleTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $expectedScore = now()->timestamp + 120;

        // Variadic ZADD for each tag (one command per tag, all keys as members)
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo')
            ->andReturn($client);
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'ns:foo')
            ->andReturn($client);

        // SETEX for the key
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 120, serialize('bar'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            120,
            ['_all:tag:users:entries', '_all:tag:posts:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithEmptyTags(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        // Only SETEX, no ZADD
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            60,
            [],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithEmptyValuesReturnsTrue(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // No pipeline operations for empty values
        $client->shouldNotReceive('pipeline');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            [],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyInClusterModeUsesVariadicZadd(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        $expectedScore = now()->timestamp + 60;

        // Variadic ZADD: one command with all members for the tag
        // This works in cluster because all members go to ONE sorted set (one slot)
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:baz')
            ->andReturn(2);

        // Sequential SETEX for each key
        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->andReturn(true);

        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:baz', 60, serialize('qux'))
            ->andReturn(true);

        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar', 'baz' => 'qux'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);
        $client->shouldReceive('setex')->andReturn($client);

        // One SETEX fails
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true, 1, false]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar', 'baz' => 'qux'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testPutManyReturnsFalseOnPipelineFailure(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);
        $client->shouldReceive('setex')->andReturn($client);

        // Pipeline fails entirely
        $client->shouldReceive('exec')
            ->once()
            ->andReturn(false);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testPutManyEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);

        // TTL should be at least 1
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 1, serialize('bar'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            0,  // Zero TTL
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyWithNumericValues(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $client->shouldReceive('zadd')->andReturn($client);

        // Numeric values are NOT serialized (optimization)
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:count', 60, 42)
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['count' => 42],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyUsesCorrectPrefix(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $expectedScore = now()->timestamp + 30;

        // Custom prefix should be used
        $client->shouldReceive('zadd')
            ->once()
            ->with('custom:_all:tag:users:entries', $expectedScore, 'ns:foo')
            ->andReturn($client);

        $client->shouldReceive('setex')
            ->once()
            ->with('custom:ns:foo', 30, serialize('bar'))
            ->andReturn($client);

        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, true]);

        $store = $this->createStore($connection, 'custom:');
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            30,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * Tests the maximum optimization benefit: multiple keys × multiple tags.
     * Before: O(keys × tags) ZADD commands
     * After: O(tags) ZADD commands (each with all keys)
     */
    public function testPutManyWithMultipleTagsAndMultipleKeys(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('pipeline')->once()->andReturn($client);

        $expectedScore = now()->timestamp + 60;

        // Variadic ZADD for first tag with all keys
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:a', $expectedScore, 'ns:b', $expectedScore, 'ns:c')
            ->andReturn($client);

        // Variadic ZADD for second tag with all keys
        $client->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'ns:a', $expectedScore, 'ns:b', $expectedScore, 'ns:c')
            ->andReturn($client);

        // SETEX for each key
        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:a', 60, serialize('val-a'))
            ->andReturn($client);

        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:b', 60, serialize('val-b'))
            ->andReturn($client);

        $client->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:c', 60, serialize('val-c'))
            ->andReturn($client);

        // Results: 2 ZADDs + 3 SETEXs
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([3, 3, true, true, true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['a' => 'val-a', 'b' => 'val-b', 'c' => 'val-c'],
            60,
            ['_all:tag:users:entries', '_all:tag:posts:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyInClusterModeWithMultipleTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        $expectedScore = now()->timestamp + 60;

        // Variadic ZADD for each tag (different slots, separate commands)
        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:bar')
            ->andReturn(2);

        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:bar')
            ->andReturn(2);

        // SETEXs for each key
        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->andReturn(true);

        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:bar', 60, serialize('value2'))
            ->andReturn(true);

        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'value1', 'bar' => 'value2'],
            60,
            ['_all:tag:users:entries', '_all:tag:posts:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyInClusterModeWithEmptyTags(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        // No ZADD calls for empty tags
        $clusterClient->shouldNotReceive('zadd');

        // Only SETEXs
        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->andReturn(true);

        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            60,
            [],
            'ns:'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyInClusterModeReturnsFalseOnSetexFailure(): void
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        [$store, $clusterClient] = $this->createClusterStore();

        $expectedScore = now()->timestamp + 60;

        $clusterClient->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:bar')
            ->andReturn(2);

        // First SETEX succeeds, second fails
        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->andReturn(true);

        $clusterClient->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:bar', 60, serialize('value2'))
            ->andReturn(false);

        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'value1', 'bar' => 'value2'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testPutManyInClusterModeWithEmptyValuesReturnsTrue(): void
    {
        [$store, $clusterClient] = $this->createClusterStore();

        // No operations for empty values
        $clusterClient->shouldNotReceive('zadd');
        $clusterClient->shouldNotReceive('setex');

        $result = $store->allTagOps()->putMany()->execute(
            [],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }
}
