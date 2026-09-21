<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the Increment operation (intersection tags).
 */
class IncrementTest extends RedisCacheTestCase
{
    public function testIncrementWithTagsInLuaMode(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter', 'prefix:_all:tag:users:entries'], [1, -1, 'counter'])
            ->andReturn('5');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(5, $result);
    }

    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter', 'prefix:_all:tag:users:entries'], [10, -1, 'counter'])
            ->andReturn('15');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            10,
            ['_all:tag:users:entries']
        );

        $this->assertSame(15, $result);
    }

    public function testIncrementWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter', 'prefix:_all:tag:users:entries', 'prefix:_all:tag:posts:entries'], [1, -1, 'counter'])
            ->andReturn('1');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertSame(1, $result);
    }

    public function testIncrementWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:counter'], [1, -1, 'counter'])
            ->andReturn('1');

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            []
        );

        $this->assertSame(1, $result);
    }

    public function testIncrementInClusterModeUsesSequentialCommands(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->shouldNotReceive('pipeline');

        $connection->expects('incrby')
            ->with('prefix:counter', 1)
            ->andReturn(10)
            ->ordered();
        $connection->expects('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->andReturn(1)
            ->ordered();

        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(10, $result);
    }

    public function testIncrementInClusterModeDoesNotPublishTagsWhenTheCounterFails(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        $connection->expects('incrby')->with('prefix:counter', 1)->andReturnFalse();
        $connection->shouldNotReceive('zadd');

        $this->assertFalse($store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        ));
    }

    public function testIncrementReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')->andReturn(false);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }
}
