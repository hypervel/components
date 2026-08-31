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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1061, 'mykey')
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $expectedScore = 1121;

        // ZADD for each tag
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->andReturn($connection);

        // SETEX for cache value
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 120, serialize('myvalue'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1, 1]);

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // No ZADD calls expected
        // SETEX for cache value
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
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

    public function testPutUsesCorrectPrefix(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('custom:_all:tag:users:entries', 1031, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('setex')
            ->once()
            ->with('custom:mykey', 30, serialize('myvalue'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('setex')->andReturn($connection);

        // SETEX returns false (failure)
        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([false, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    public function testPutInClusterModeUsesSequentialCommands(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, serialize('myvalue'))
            ->andReturn(true)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1061, 'mykey')
            ->andReturn(1)
            ->ordered();

        $result = $store->allTagOps()->put()->execute(
            'mykey',
            'myvalue',
            60,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testPutEnforcesMinimumTtlOfOne(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1002, 'mykey')
            ->andReturn($connection);

        // TTL should be at least 1
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 1, serialize('myvalue'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

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

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('setex')
            ->once()
            ->with('prefix:mykey', 60, 42)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

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
