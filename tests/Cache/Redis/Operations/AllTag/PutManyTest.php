<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the PutMany operation (intersection tags).
 */
class PutManyTest extends RedisCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
    }

    public function testPutManyWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $expectedScore = 1061;

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:baz', 60, serialize('qux'))
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:baz')
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, true, 0]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar', 'baz' => 'qux'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    public function testPutManyWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $expectedScore = 1121;

        // Variadic ZADD for each tag (one command per tag, all keys as members)
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo')
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'ns:foo')
            ->andReturn($connection);

        // SETEX for the key
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 120, serialize('bar'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            120,
            ['_all:tag:users:entries', '_all:tag:posts:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    public function testPutManyWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // Only SETEX, no ZADD
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
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

    public function testPutManyWithEmptyValuesReturnsTrue(): void
    {
        $connection = $this->mockConnection();

        // No pipeline operations for empty values
        $connection->shouldNotReceive('pipeline');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            [],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    public function testPutManyInClusterModeUsesVariadicZadd(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        $expectedScore = 1061;

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->andReturn(true)
            ->ordered();

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:baz', 60, serialize('qux'))
            ->andReturn(true)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:baz')
            ->andReturn(0)
            ->ordered();

        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar', 'baz' => 'qux'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    public function testPutManyReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('setex')->andReturn($connection);

        // One SETEX fails
        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, false, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar', 'baz' => 'qux'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertFalse($result);
    }

    public function testPutManyReturnsFalseOnPipelineFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('setex')->andReturn($connection);

        // Pipeline fails entirely
        $connection->shouldReceive('exec')
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

    public function testPutManyReturnsFalseWhenPipelineMembershipWriteFails(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('setex')->twice()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([true, true, false]);

        $store = $this->createStore($connection);

        $this->assertFalse($store->allTagOps()->putMany()->execute(
            ['foo' => 'bar', 'baz' => 'qux'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        ));
    }

    public function testPutManyEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1002, 'ns:foo')
            ->andReturn($connection);

        // TTL should be at least 1
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 1, serialize('bar'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            0,  // Zero TTL
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    public function testPutManyWithNumericValues(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:count', 60, 42)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['count' => 42],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    public function testPutManyUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $expectedScore = 1031;

        // Custom prefix should be used
        $connection->shouldReceive('zadd')
            ->once()
            ->with('custom:_all:tag:users:entries', $expectedScore, 'ns:foo')
            ->andReturn($connection);

        $connection->shouldReceive('setex')
            ->once()
            ->with('custom:ns:foo', 30, serialize('bar'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

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
     * Tests the maximum optimization benefit: multiple keys × multiple tags.
     * Before: O(keys × tags) ZADD commands
     * After: O(tags) ZADD commands (each with all keys).
     */
    public function testPutManyWithMultipleTagsAndMultipleKeys(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $expectedScore = 1061;

        // Variadic ZADD for first tag with all keys
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:a', $expectedScore, 'ns:b', $expectedScore, 'ns:c')
            ->andReturn($connection);

        // Variadic ZADD for second tag with all keys
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'ns:a', $expectedScore, 'ns:b', $expectedScore, 'ns:c')
            ->andReturn($connection);

        // SETEX for each key
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:a', 60, serialize('val-a'))
            ->andReturn($connection);

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:b', 60, serialize('val-b'))
            ->andReturn($connection);

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:c', 60, serialize('val-c'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, true, true, 3, 3]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->putMany()->execute(
            ['a' => 'val-a', 'b' => 'val-b', 'c' => 'val-c'],
            60,
            ['_all:tag:users:entries', '_all:tag:posts:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }

    public function testPutManyInClusterModeWithMultipleTags(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $expectedScore = 1061;

        // Variadic ZADD for each tag (different slots, separate commands)
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:bar')
            ->andReturn(2);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'ns:foo', $expectedScore, 'ns:bar')
            ->andReturn(2);

        // SETEXs for each key
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->andReturn(true);

        $connection->shouldReceive('setex')
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

    public function testPutManyInClusterModeWithEmptyTags(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // No ZADD calls for empty tags
        $connection->shouldNotReceive('zadd');

        // Only SETEXs
        $connection->shouldReceive('setex')
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

    public function testPutManyInClusterModeReturnsFalseOnSetexFailure(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $expectedScore = 1061;

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'ns:foo')
            ->andReturn(1);

        // First SETEX succeeds, second fails
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->andReturn(true);

        $connection->shouldReceive('setex')
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

    public function testPutManyInClusterModeSkipsMembershipsWhenAllWritesFail(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->andReturn(false);

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:ns:bar', 60, serialize('value2'))
            ->andReturn(false);

        $connection->shouldNotReceive('zadd');

        $result = $store->allTagOps()->putMany()->execute(
            ['foo' => 'value1', 'bar' => 'value2'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertFalse($result);
    }

    public function testPutManyInClusterModeReturnsFalseWhenMembershipWriteFails(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->shouldReceive('setex')->once()->andReturn(true);
        $connection->shouldReceive('zadd')->once()->andReturn(false);

        $this->assertFalse($store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        ));
    }

    public function testPutManyInClusterModeWithEmptyValuesReturnsTrue(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // No operations for empty values
        $connection->shouldNotReceive('zadd');
        $connection->shouldNotReceive('setex');

        $result = $store->allTagOps()->putMany()->execute(
            [],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }
}
