<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hypervel\Cache\Redis\AllTagSet;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;

/**
 * Tests for AllTagSet class.
 *
 * Note: Operation-specific tests (addEntry, entries, flushStaleEntries) have been
 * moved to dedicated test classes in tests/Cache/Redis/Operations/AllTag/.
 *
 * This file tests the TagSet-specific API methods: tagId, tagKey, flushTag, resetTag.
 *
 * @internal
 * @coversNothing
 */
class AllTagSetTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testFlushTagCallsResetTag(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        // resetTag calls store->forget which uses del
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $result = $tagSet->flushTag('users');

        // Returns the tag identifier
        $this->assertSame('_all:tag:users:entries', $result);
    }

    /**
     * @test
     */
    public function testResetTagDeletesTagAndReturnsId(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $result = $tagSet->resetTag('users');

        $this->assertSame('_all:tag:users:entries', $result);
    }

    /**
     * @test
     */
    public function testTagIdReturnsCorrectFormat(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        $this->assertSame('_all:tag:users:entries', $tagSet->tagId('users'));
        $this->assertSame('_all:tag:posts:entries', $tagSet->tagId('posts'));
    }

    /**
     * @test
     */
    public function testTagKeyReturnsCorrectFormat(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        // In AllTagSet, tagKey and tagId return the same value
        $this->assertSame('_all:tag:users:entries', $tagSet->tagKey('users'));
    }

    /**
     * @test
     */
    public function testTagIdsReturnsArrayOfTagIdentifiers(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users', 'posts', 'comments']);

        $tagIds = $tagSet->tagIds();

        $this->assertSame([
            '_all:tag:users:entries',
            '_all:tag:posts:entries',
            '_all:tag:comments:entries',
        ], $tagIds);
    }

    /**
     * @test
     */
    public function testGetNamesReturnsOriginalTagNames(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users', 'posts']);

        $this->assertSame(['users', 'posts'], $tagSet->getNames());
    }
}
