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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $expectedScore = 1061;

        $connection->client()->expects('setex')
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('setex')
            ->with('prefix:ns:baz', 60, serialize('qux'))
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $expectedScore, 'ns:foo', $expectedScore, 'ns:baz')
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('exec')
            ->returns([true, true, 0]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $expectedScore = 1121;

        // Variadic ZADD for each tag (one command per tag, all keys as members)
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $expectedScore, 'ns:foo')
            ->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', (float) $expectedScore, 'ns:foo')
            ->returns($connection->client());

        // SETEX for the key
        $connection->client()->expects('setex')
            ->with('prefix:ns:foo', 120, serialize('bar'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1, 1]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());

        // Only SETEX, no ZADD
        $connection->client()->expects('setex')
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true]);

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
        $connection->client()->expects('pipeline')->never();

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
        [$store, $client] = $this->createClusterStore();

        // The last value's TTL starts three seconds later, so memberships are scored after the writes.
        $expectedScore = 1064;

        $client->expects('setex')
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->returns(true)
            ->ordered();

        $client->expects('setex')
            ->with('prefix:ns:baz', 60, serialize('qux'))
            ->resolves(function (): bool {
                CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1003.900000'));

                return true;
            })
            ->ordered();

        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $expectedScore, 'ns:foo', $expectedScore, 'ns:baz')
            ->returns(0)
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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());
        $connection->client()->allows('setex')->returns($connection->client());

        // One SETEX fails
        $connection->client()->expects('exec')
            ->returns([true, false, 1]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());
        $connection->client()->allows('setex')->returns($connection->client());

        // Pipeline fails entirely
        $connection->client()->expects('exec')
            ->returns(false);

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
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('setex')->times(2)->returns($connection->client());
        $connection->client()->expects('zadd')->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, true, false]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1002.0, 'ns:foo')
            ->returns($connection->client());

        // TTL should be at least 1
        $connection->client()->expects('setex')
            ->with('prefix:ns:foo', 1, serialize('bar'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());

        // Numeric values are NOT serialized (optimization)
        $connection->client()->expects('setex')
            ->with('prefix:ns:count', 60, 42)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $expectedScore = 1031;

        // Custom prefix should be used
        $connection->client()->expects('zadd')
            ->with('custom:_all:tag:users:entries', (float) $expectedScore, 'ns:foo')
            ->returns($connection->client());

        $connection->client()->expects('setex')
            ->with('custom:ns:foo', 30, serialize('bar'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

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

        $connection->client()->expects('pipeline')->returns($connection->client());

        $expectedScore = 1061;

        // Variadic ZADD for first tag with all keys
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $expectedScore, 'ns:a', $expectedScore, 'ns:b', $expectedScore, 'ns:c')
            ->returns($connection->client());

        // Variadic ZADD for second tag with all keys
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', (float) $expectedScore, 'ns:a', $expectedScore, 'ns:b', $expectedScore, 'ns:c')
            ->returns($connection->client());

        // SETEX for each key
        $connection->client()->expects('setex')
            ->with('prefix:ns:a', 60, serialize('val-a'))
            ->returns($connection->client());

        $connection->client()->expects('setex')
            ->with('prefix:ns:b', 60, serialize('val-b'))
            ->returns($connection->client());

        $connection->client()->expects('setex')
            ->with('prefix:ns:c', 60, serialize('val-c'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, true, true, 3, 3]);

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
        [$store, $client] = $this->createClusterStore();

        $expectedScore = 1061;

        // Variadic ZADD for each tag (different slots, separate commands)
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $expectedScore, 'ns:foo', $expectedScore, 'ns:bar')
            ->returns(2);

        $client->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', (float) $expectedScore, 'ns:foo', $expectedScore, 'ns:bar')
            ->returns(2);

        // SETEXs for each key
        $client->expects('setex')
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->returns(true);

        $client->expects('setex')
            ->with('prefix:ns:bar', 60, serialize('value2'))
            ->returns(true);

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
        [$store, $client] = $this->createClusterStore();

        // No ZADD calls for empty tags
        $client->expects('zadd')->never();

        // Only SETEXs
        $client->expects('setex')
            ->with('prefix:ns:foo', 60, serialize('bar'))
            ->returns(true);

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
        [$store, $client] = $this->createClusterStore();

        $expectedScore = 1061;

        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', (float) $expectedScore, 'ns:foo')
            ->returns(1);

        // First SETEX succeeds, second fails
        $client->expects('setex')
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->returns(true);

        $client->expects('setex')
            ->with('prefix:ns:bar', 60, serialize('value2'))
            ->returns(false);

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
        [$store, $client] = $this->createClusterStore();

        $client->expects('setex')
            ->with('prefix:ns:foo', 60, serialize('value1'))
            ->returns(false);

        $client->expects('setex')
            ->with('prefix:ns:bar', 60, serialize('value2'))
            ->returns(false);

        $client->expects('zadd')->never();

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
        [$store, $client] = $this->createClusterStore();

        $client->expects('setex')->returns(true);
        $client->expects('zadd')->returns(false);

        $this->assertFalse($store->allTagOps()->putMany()->execute(
            ['foo' => 'bar'],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        ));
    }

    public function testPutManyInClusterModeWithEmptyValuesReturnsTrue(): void
    {
        [$store, $client] = $this->createClusterStore();

        // No operations for empty values
        $client->expects('zadd')->never();
        $client->expects('setex')->never();

        $result = $store->allTagOps()->putMany()->execute(
            [],
            60,
            ['_all:tag:users:entries'],
            'ns:'
        );

        $this->assertTrue($result);
    }
}
