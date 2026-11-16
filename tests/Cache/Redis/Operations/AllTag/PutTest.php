<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Put operation (intersection tags).
 */
class PutTest extends RedisCacheTestCase
{
    public function testPutStoresValueWithTagsInPipelineMode(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('setex')
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1061.0, 'mykey')
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('exec')
            ->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testPutWithMultipleTags(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $expectedScore = 1121.0;

        // ZADD for each tag
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->returns($connection->client());

        // SETEX for cache value
        $connection->client()->expects('setex')
            ->with('prefix:mykey', 120, serialize('myvalue'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            120,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertTrue($result);
    }

    public function testPutWithEmptyTagsStillStoresValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // No ZADD calls expected
        // SETEX for cache value
        $connection->client()->expects('setex')
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            []
        );

        $this->assertTrue($result);
    }

    public function testPutUsesCorrectPrefix(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('custom:_all:tag:users:entries', 1031.0, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('setex')
            ->with('custom:mykey', 30, serialize('myvalue'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

        $store = $this->createStore($connection, 'custom:');
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            30,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testPutReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());
        $connection->client()->allows('setex')->returns($connection->client());

        // SETEX returns false (failure)
        $connection->client()->expects('exec')
            ->returns([false, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    public function testPutReturnsFalseWhenPipelineMembershipWriteFails(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('setex')->returns($connection->client());
        $connection->client()->expects('zadd')->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, false]);

        $store = $this->createStore($connection);

        $this->assertFalse($store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testPutTreatsZeroPipelineMembershipResultAsSuccess(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('setex')->returns($connection->client());
        $connection->client()->expects('zadd')->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, 0]);

        $store = $this->createStore($connection);

        $this->assertTrue($store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testPutInClusterModeUsesSequentialCommands(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$store, $client] = $this->createClusterStore();

        $client->expects('setex')
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->returns(true)
            ->ordered();

        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1061.0, 'mykey')
            ->returns(1)
            ->ordered();

        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testPutInClusterModeDoesNotPublishTagsWhenTheValueWriteFails(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('setex')->with('prefix:mykey', 60, serialize('myvalue'))->returns(false);
        $client->expects('zadd')->never();

        $this->assertFalse($store->allTagOps()->put()->execute('mykey', 'myvalue', 60, ['_all:tag:users:entries']));
    }

    public function testPutReturnsFalseWhenClusterMembershipWriteFails(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('setex')->returns(true);
        $client->expects('zadd')->returns(false);

        $this->assertFalse($store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testPutTreatsZeroClusterMembershipResultAsSuccess(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('setex')->returns(true);
        $client->expects('zadd')->returns(0);

        $this->assertTrue($store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testPutEnforcesMinimumTtlOfOne(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1002.0, 'mykey')
            ->returns($connection->client());

        // TTL should be at least 1
        $connection->client()->expects('setex')
            ->with('prefix:mykey', 1, serialize('myvalue'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            0,  // Zero TTL
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testPutWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());

        // Numeric values are NOT serialized (optimization)
        $connection->client()->expects('setex')
            ->with('prefix:mykey', 60, 42)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

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
