<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Add operation (intersection tags).
 *
 * Uses native Redis SET with NX (only set if Not eXists) and EX (expiration)
 * flags for atomic "add if not exists" semantics.
 */
class AddTest extends RedisCacheTestCase
{
    public function testAddWithTagsReturnsTrueWhenKeyAdded(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:mykey', 'prefix:_all:tag:users:entries'], [serialize('myvalue'), 60, 1061, 'mykey'])
            ->returns(1);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testAddWithTagsReturnsFalseWhenKeyExists(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')->returns(0);
        $connection->client()->expects('zadd')->never();

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    public function testAddWithMultipleTags(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(
                Argument::type('string'),
                ['prefix:mykey', 'prefix:_all:tag:users:entries', 'prefix:_all:tag:posts:entries'],
                [serialize('myvalue'), 120, 1121, 'mykey'],
            )
            ->returns(1);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            120,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertTrue($result);
    }

    public function testAddWithEmptyTagsSkipsLua(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')->never();

        // Only SET NX EX for add
        $connection->client()->expects('set')
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->returns(true);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            []
        );

        $this->assertTrue($result);
    }

    public function testAddInClusterModeUsesSequentialCommands(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$store, $client] = $this->createClusterStore();

        $client->expects('set')
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->returns(true)
            ->ordered();

        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1061.0, 'mykey')
            ->returns(0)
            ->ordered();

        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testAddInClusterModeReturnsFalseWhenKeyExists(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$store, $client] = $this->createClusterStore();

        $client->expects('set')
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->returns(false)
            ->ordered();

        $client->expects('zadd')->never();

        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    public function testAddReturnsFalseWhenScriptExecutionFails(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')->returns(false);

        $store = $this->createStore($connection);

        $this->assertFalse($store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testAddReturnsFalseWhenClusterMembershipWriteFails(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('set')->returns(true);
        $client->expects('zadd')->returns(false);

        $this->assertFalse($store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testAddEnforcesMinimumTtlOfOne(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:mykey', 'prefix:_all:tag:users:entries'], [serialize('myvalue'), 1, 1002, 'mykey'])
            ->returns(1);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            0,  // Zero TTL
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testAddWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:mykey', 'prefix:_all:tag:users:entries'], ['42', 60, 946684860, 'mykey'])
            ->returns(1);

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
