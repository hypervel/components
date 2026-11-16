<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Generator;
use Hypervel\Cache\Redis\Operations\AnyTag\Flush;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTaggedKeys;
use Hypervel\Cache\Redis\Operations\AnyTag\RemoveEmptyTags;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Flush operation (union tags).
 */
class FlushTest extends RedisCacheTestCase
{
    public function testFlushDeletesCacheEntriesReverseIndexesAndScannedFields(): void
    {
        $connection = $this->mockConnection();

        // Mock GetTaggedKeys to return cache keys
        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')
            ->with('users')
            ->returns($this->arrayToGenerator(['key1', 'key2']));

        $connection->expects('evalWithShaCache')
            ->with(
                Argument::type('string'),
                ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key2:_any:tags', 'prefix:key1', 'prefix:key2'],
                [2, 'key1', 'key2'],
            )->returns(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1])->ordered();

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));

        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    public function testFlushWithMultipleTagsOnlyRemovesKeysFromTheirOwnTag(): void
    {
        $connection = $this->mockConnection();

        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')
            ->with('users')
            ->returns($this->arrayToGenerator(['shared_key', 'user_only']));
        $getTaggedKeys->expects('execute')
            ->with('posts')
            ->returns($this->arrayToGenerator(['shared_key', 'post_only']));

        $connection->expects('evalWithShaCache')
            ->with(
                Argument::type('string'),
                ['prefix:_any:tag:users:entries', 'prefix:shared_key:_any:tags', 'prefix:user_only:_any:tags', 'prefix:shared_key', 'prefix:user_only'],
                [2, 'shared_key', 'user_only'],
            )->returns(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(
                Argument::type('string'),
                ['prefix:_any:tag:posts:entries', 'prefix:shared_key:_any:tags', 'prefix:post_only:_any:tags', 'prefix:shared_key', 'prefix:post_only'],
                [2, 'shared_key', 'post_only'],
            )->returns(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries'], ['users', 'posts'])
            ->returns([2, 2])->ordered();

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));

        $result = $operation->execute(['users', 'posts']);
        $this->assertTrue($result);
    }

    public function testFlushWithNoEntriesChecksForEmptyTags(): void
    {
        $connection = $this->mockConnection();

        // Mock GetTaggedKeys to return empty
        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')
            ->with('users')
            ->returns($this->arrayToGenerator([]));

        $connection->client()->expects('del')->never();
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));

        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    public function testFlushWithNoTagsDoesNotSendCommands(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')->never();
        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')->never();
        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));

        $this->assertTrue($operation->execute([]));
    }

    public function testFlushUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')
            ->with('users')
            ->returns($this->arrayToGenerator(['mykey']));

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['custom_prefix:_any:tag:users:entries', 'custom_prefix:mykey:_any:tags', 'custom_prefix:mykey'], [1, 'mykey'])
            ->returns(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['custom_prefix:_any:tag:registry', 'custom_prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1])->ordered();

        $store = $this->createStore($connection, 'custom_prefix:');
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));

        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    public function testFlushClusterModeUsesSequentialCommands(): void
    {
        [$store, $client] = $this->createClusterStore(tagMode: 'any');

        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')
            ->with('users')
            ->returns($this->arrayToGenerator(['key1', 'key2']));

        $cleanup = [];
        $client->expects('hdel')
            ->with('prefix:_any:tag:users:entries', 'key1', 'key2')
            ->resolves(function () use (&$cleanup): int {
                $cleanup[] = 'hdel';

                return 2;
            });

        // Sequential del for reverse indexes
        $client->expects('del')
            ->with('prefix:key1:_any:tags', 'prefix:key2:_any:tags')
            ->resolves(function () use (&$cleanup): int {
                $cleanup[] = 'del';

                return 2;
            });

        // Sequential unlink for cache keys
        $client->expects('unlink')
            ->with('prefix:key1', 'prefix:key2')
            ->resolves(function () use (&$cleanup): int {
                $cleanup[] = 'unlink';

                return 2;
            });

        $client->expects('hlen')->times(2)
            ->with('prefix:_any:tag:users:entries')
            ->resolves(function () use (&$cleanup): int {
                $cleanup[] = 'hlen';

                return 0;
            });

        // Sequential zrem for registry
        $client->expects('zrem')
            ->with('prefix:_any:tag:registry', 'users')
            ->resolves(function () use (&$cleanup): int {
                $cleanup[] = 'zrem';

                return 1;
            });

        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));
        $result = $operation->execute(['users']);
        $this->assertTrue($result);
        $this->assertSame(['hdel', 'del', 'unlink', 'hlen', 'zrem', 'hlen'], $cleanup);
    }

    public function testFlushClusterModeWithMultipleTags(): void
    {
        [$store, $client] = $this->createClusterStore(tagMode: 'any');

        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')
            ->with('users')
            ->returns($this->arrayToGenerator(['user_key', 'shared_key']));
        $getTaggedKeys->expects('execute')
            ->with('posts')
            ->returns($this->arrayToGenerator(['shared_key', 'post_key']));

        // Each tag's chunk only removes fields from that tag's hash.
        $client->expects('hdel')->with('prefix:_any:tag:users:entries', 'user_key', 'shared_key')->returns(2);
        $client->expects('hdel')->with('prefix:_any:tag:posts:entries', 'shared_key', 'post_key')->returns(2);
        $client->expects('hlen')->with('prefix:_any:tag:users:entries')->times(2)->returns(0);
        $client->expects('hlen')->with('prefix:_any:tag:posts:entries')->times(2)->returns(0);
        $client->expects('del')->with('prefix:user_key:_any:tags', 'prefix:shared_key:_any:tags')->returns(2);
        $client->expects('del')->with('prefix:shared_key:_any:tags', 'prefix:post_key:_any:tags')->returns(1);
        $client->expects('unlink')->with('prefix:user_key', 'prefix:shared_key')->returns(2);
        $client->expects('unlink')->with('prefix:shared_key', 'prefix:post_key')->returns(1);
        $client->expects('zrem')->with('prefix:_any:tag:registry', 'users')->returns(1);
        $client->expects('zrem')->with('prefix:_any:tag:registry', 'posts')->returns(1);

        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));
        $result = $operation->execute(['users', 'posts']);
        $this->assertTrue($result);
    }

    public function testFlushViaRedisStoreMethod(): void
    {
        $connection = $this->mockConnection();

        // Mock hlen/hkeys for GetTaggedKeys internal calls
        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(1);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['mykey']);

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:users:entries', 'prefix:mykey:_any:tags', 'prefix:mykey'], [1, 'mykey'])
            ->returns(1);
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->returns([1, 1]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $result = $store->anyTagOps()->flush()->execute(['users']);
        $this->assertTrue($result);
    }

    public function testClusterFlushPreservesRegistrationWhenTheHashWasRepopulated(): void
    {
        [$store, $client] = $this->createClusterStore(tagMode: 'any');
        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')->with('users')->returns($this->arrayToGenerator([]));

        $client->expects('hlen')->with('prefix:_any:tag:users:entries')->returns(1);
        $client->expects('zrem')->never();

        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));

        $this->assertTrue($operation->execute(['users']));
    }

    public function testClusterFlushRestoresRegistrationWhenAWriterRacesWithDeregistration(): void
    {
        [$store, $client] = $this->createClusterStore(tagMode: 'any');
        $getTaggedKeys = Double::for(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')->with('users')->returns($this->arrayToGenerator([]));

        $cleanup = [];
        $lengths = [0, 1];
        $client->expects('hlen')->times(2)
            ->with('prefix:_any:tag:users:entries')
            ->resolves(function () use (&$cleanup, &$lengths): int {
                $cleanup[] = 'hlen';

                return array_shift($lengths);
            });
        $client->expects('zrem')
            ->with('prefix:_any:tag:registry', 'users')
            ->resolves(function () use (&$cleanup): int {
                $cleanup[] = 'zrem';

                return 1;
            });
        $client->expects('zadd')
            ->with('prefix:_any:tag:registry', ['NX'], StoreContext::MAX_EXPIRY, 'users')
            ->resolves(function () use (&$cleanup): int {
                $cleanup[] = 'zadd';

                return 1;
            });

        $operation = new Flush($store->getContext(), $getTaggedKeys, new RemoveEmptyTags($store->getContext()));

        $this->assertTrue($operation->execute(['users']));
        $this->assertSame(['hlen', 'zrem', 'hlen', 'zadd'], $cleanup);
    }

    /**
     * Convert an array to a generator.
     *
     * @param array<string> $items
     * @return Generator<string>
     */
    private function arrayToGenerator(array $items): Generator
    {
        foreach ($items as $item) {
            yield $item;
        }
    }
}
