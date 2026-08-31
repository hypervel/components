<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection)->ordered();
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn($connection)
            ->ordered();
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1061, 'mykey')
            ->andReturn($connection)
            ->ordered();
        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1])
            ->ordered();

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection)->ordered();
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn($connection)
            ->ordered();
        $connection->shouldReceive('zadd')->andReturn($connection)->ordered();
        $connection->shouldReceive('exec')->andReturn([false, 1])->ordered();

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $expectedScore = 1121;

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 120, 'NX'])
            ->andReturn($connection)
            ->ordered();

        // ZADD for each tag
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->andReturn($connection)
            ->ordered();
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1, 1])
            ->ordered();

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            120,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertTrue($result);
    }

    public function testAddWithEmptyTagsSkipsPipeline(): void
    {
        $connection = $this->mockConnection();

        // No pipeline operations for empty tags
        $connection->shouldNotReceive('pipeline');

        // Only SET NX EX for add
        $connection->shouldReceive('set')
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

    public function testAddInClusterModeUsesSequentialCommands(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn(true)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1061, 'mykey')
            ->andReturn(0)
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

        [$store, , $connection] = $this->createClusterStore();

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 60, 'NX'])
            ->andReturn(false)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1061, 'mykey')
            ->andReturn(1)
            ->ordered();

        $result = $store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    public function testAddReturnsFalseWhenPipelineMembershipWriteFails(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('set')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([true, false]);

        $store = $this->createStore($connection);

        $this->assertFalse($store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testAddReturnsFalseWhenPipelineExecutionFails(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('set')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn(false);

        $store = $this->createStore($connection);

        $this->assertFalse($store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testAddTreatsZeroPipelineMembershipResultAsSuccess(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('set')->once()->andReturn($connection);
        $connection->shouldReceive('zadd')->once()->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([true, 0]);

        $store = $this->createStore($connection);

        $this->assertTrue($store->allTagOps()->add()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        ));
    }

    public function testAddReturnsFalseWhenClusterMembershipWriteFails(): void
    {
        [$store, , $connection] = $this->createClusterStore();
        $connection->shouldReceive('set')->once()->andReturn(true);
        $connection->shouldReceive('zadd')->once()->andReturn(false);

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'), ['EX' => 1, 'NX'])
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1002, 'mykey')
            ->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([true, 1]);

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', 42, ['EX' => 60, 'NX'])
            ->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([true, 1]);

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
