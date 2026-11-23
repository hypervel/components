<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Generator;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\PhpRedisConnection;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Matching\Argument;

class AnyTagSetTest extends RedisCacheTestCase
{
    private RedisStore $store;

    private PhpRedisConnection&DoubleInterface $connection;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupStore();
    }

    public function testGetNamesReturnsTagNames(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        $this->assertSame(['users', 'posts'], $tagSet->getNames());
    }

    public function testGetNamesReturnsEmptyArrayWhenNoTags(): void
    {
        $tagSet = new AnyTagSet($this->store, []);

        $this->assertSame([], $tagSet->getNames());
    }

    public function testTagHashKeyReturnsCorrectFormat(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        $result = $tagSet->tagHashKey('users');

        $this->assertSame('prefix:_any:tag:users:entries', $result);
    }

    public function testEntriesReturnsGeneratorOfKeys(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        // GetTaggedKeys checks HLEN then uses HKEYS for small hashes
        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(3);

        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1', 'key2', 'key3']);

        $entries = $tagSet->entries();

        $this->assertInstanceOf(Generator::class, $entries);
        $this->assertSame(['key1', 'key2', 'key3'], iterator_to_array($entries));
    }

    public function testEntriesDeduplicatesAcrossTags(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        // First tag 'users' has keys key1, key2
        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(2);
        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1', 'key2']);

        // Second tag 'posts' has keys key2, key3 (key2 is duplicate)
        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:posts:entries')
            ->returns(2);
        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:posts:entries')
            ->returns(['key2', 'key3']);

        $entries = $tagSet->entries();

        // Should deduplicate 'key2'
        $result = iterator_to_array($entries);
        $this->assertCount(3, $result);
        $this->assertSame(['key1', 'key2', 'key3'], array_values($result));
    }

    public function testEntriesWithEmptyTagReturnsEmpty(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(0);
        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns([]);

        $entries = $tagSet->entries();

        $this->assertSame([], iterator_to_array($entries));
    }

    public function testEntriesWithNoTagsReturnsEmpty(): void
    {
        $tagSet = new AnyTagSet($this->store, []);

        $entries = $tagSet->entries();

        $this->assertSame([], iterator_to_array($entries));
    }

    public function testFlushDeletesKeysAndScannedMemberships(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users']);

        // GetTaggedKeys for the flush operation
        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(2);
        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1', 'key2']);

        $this->connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key2:_any:tags', 'prefix:key1', 'prefix:key2'], [2, 'key1', 'key2'])
            ->returns(1);
        $this->connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1]);

        $this->assertTrue($tagSet->flush());
    }

    public function testFlushTagDeletesSingleTag(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        // GetTaggedKeys for the flush operation (only 'users' tag)
        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(1);
        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1']);

        $this->connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key1'], [1, 'key1'])
            ->returns(1);
        $this->connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1]);

        $result = $tagSet->flushTag('users');

        $this->assertSame('prefix:_any:tag:users:entries', $result);
    }

    public function testResetCallsFlush(): void
    {
        $tagSet = new AnyTagSet($this->store, ['users', 'posts']);

        // GetTaggedKeys for both tags
        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(1);
        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1']);

        $this->connection->client()->expects('hlen')
            ->with('prefix:_any:tag:posts:entries')
            ->returns(1);
        $this->connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:posts:entries')
            ->returns(['key2']);

        $this->connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key1'], [1, 'key1'])
            ->returns(1);
        $this->connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:posts:entries', 'prefix:key2:_any:tags', 'prefix:key2'], [1, 'key2'])
            ->returns(1);
        $this->connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries'], ['users', 'posts'])
            ->returns([2, 2]);

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
