<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the Decrement operation (intersection tags).
 */
class DecrementTest extends RedisCacheTestCase
{
    public function testDecrementWithTagsInLuaMode(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter', 'prefix:_all:tag:users:entries'], [1, -1, 'counter'])
            ->andReturn('5');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(5, $result);
    }

    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter', 'prefix:_all:tag:users:entries'], [10, -1, 'counter'])
            ->andReturn('-5');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            10,
            ['_all:tag:users:entries']
        );

        $this->assertSame(-5, $result);
    }

    public function testDecrementWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter', 'prefix:_all:tag:users:entries', 'prefix:_all:tag:posts:entries'], [1, -1, 'counter'])
            ->andReturn('9');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertSame(9, $result);
    }

    public function testDecrementWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter'], [1, -1, 'counter'])
            ->andReturn('-1');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            []
        );

        $this->assertSame(-1, $result);
    }

    public function testDecrementInClusterModeUsesSequentialCommands(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->shouldNotReceive('pipeline');

        $connection->expects('decrby')
            ->with('prefix:counter', 1)
            ->andReturn(0)
            ->ordered();
        $connection->expects('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn(1)
            ->ordered();

        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(0, $result);
    }

    public function testDecrementInClusterModeDoesNotPublishTagsWhenTheCounterFails(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->expects('decrby')->with('prefix:counter', 1)->andReturnFalse();
        $connection->shouldNotReceive('zadd');

        $this->assertFalse($store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        ));
    }

    public function testDecrementReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')->andReturn(false);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }
}
