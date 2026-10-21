<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Generator;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\RedisStore;
use Mockery as m;

/**
 * Tests for AnyTagSet class.
 *
 * Uses MocksRedisConnections to mock at the Redis client level,
 * allowing the full operation chain to execute.
 */
class AnyTagSetTest extends RedisCacheTestCase
{
    private RedisStore $store;

    private m\MockInterface $connection;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupStore();
    }

    /**
     * @test
     */
    public function testGetNamesReturnsTagNames(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        $this->assertSame(['users', 'posts'], $tagSet->getNames());
    }

    /**
     * @test
     */
    public function testGetNamesReturnsEmptyArrayWhenNoTags(): void
    {
        $tagSet = new AnyTagSet($this->store, []);

        $this->assertSame([], $tagSet->getNames());
    }

    /**
     * @test
     */
    public function testTagHashKeyReturnsCorrectFormat(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        $result = $tagSet->tagHashKey('users');

        $this->assertSame('prefix:_any:tag:users:entries', $result);
    }

    /**
     * @test
     */
    public function testEntriesReturnsGeneratorOfKeys(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        // GetTaggedKeys checks HLEN then uses HKEYS for small hashes
        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(3);

        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['key1', 'key2', 'key3']);

        $entries = $tagSet->entries();

        $this->assertInstanceOf(Generator::class, $entries);
        $this->assertSame(['key1', 'key2', 'key3'], iterator_to_array($entries));
    }

    /**
     * @test
     */
    public function testEntriesDeduplicatesAcrossTags(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        // First tag 'users' has keys key1, key2
        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(2);
        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['key1', 'key2']);

        // Second tag 'posts' has keys key2, key3 (key2 is duplicate)
        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(2);
        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(['key2', 'key3']);

        $entries = $tagSet->entries();

        // Should deduplicate 'key2'
        $result = iterator_to_array($entries);
        $this->assertCount(3, $result);
        $this->assertSame(['key1', 'key2', 'key3'], array_values($result));
    }

    /**
     * @test
     */
    public function testEntriesWithEmptyTagReturnsEmpty(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(0);
        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn([]);

        $entries = $tagSet->entries();

        $this->assertSame([], iterator_to_array($entries));
    }

    /**
     * @test
     */
    public function testEntriesWithNoTagsReturnsEmpty(): void
    {
        $tagSet = new AnyTagSet($this->store, []);

        $entries = $tagSet->entries();

        $this->assertSame([], iterator_to_array($entries));
    }

    /**
     * @test
     */
    public function testFlushDeletesKeysAndScannedMemberships(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        // GetTaggedKeys for the flush operation
        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(2);
        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['key1', 'key2']);

        $this->connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key2:_any:tags', 'prefix:key1', 'prefix:key2'], [1, 2, 'key1', 'key2'])
            ->andReturn(1);
        $this->connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->andReturn([1, 1]);

        $this->assertTrue($tagSet->flush());
    }

    /**
     * @test
     */
    public function testFlushTagDeletesSingleTag(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        // GetTaggedKeys for the flush operation (only 'users' tag)
        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);
        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['key1']);

        $this->connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key1'], [1, 1, 'key1'])
            ->andReturn(1);
        $this->connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->andReturn([1, 1]);

        $result = $tagSet->flushTag('users');

        $this->assertSame('prefix:_any:tag:users:entries', $result);
    }

    /**
     * @test
     */
    public function testResetCallsFlush(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        // GetTaggedKeys for both tags
        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);
        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['key1']);

        $this->connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(1);
        $this->connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(['key2']);

        $this->connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries', 'prefix:key1:_any:tags', 'prefix:key2:_any:tags', 'prefix:key1', 'prefix:key2'], [2, 2, 'key1', 'key2'])
            ->andReturn(1);
        $this->connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries'], ['users', 'posts'])
            ->andReturn([2, 2]);

        $this->assertTrue($tagSet->reset());
    }

    /**
     * Set up the store with mocked Redis connection.
     */
    private function setupStore(): void
    {
        $this->connection = $this->mockConnection();

        $this->store = $this->createStore($this->connection);
        $this->store->setTagMode('any');
    }
}
