<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Operations\AllTag\Flush;
use Hypervel\Cache\Redis\Operations\AllTag\GetEntries;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Flush operation.
 */
class FlushTest extends RedisCacheTestCase
{
    public function testFlushDeletesCacheEntriesAndScannedMemberships(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return cache keys
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection(['key1', 'key2']));

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_all:tag:users:entries', 'prefix:key1', 'prefix:key2'], ['key1', 'key2'])
            ->returns(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushWithMultipleTagsOnlyRemovesEntriesFromTheirOwnTag(): void
    {
        $connection = $this->mockConnection();

        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:people:entries'])
            ->returns(new LazyCollection(['person-1', 'person-2']));
        $getEntries->expects('execute')
            ->with(['_all:tag:artist:entries'])
            ->returns(new LazyCollection(['person-2', 'person-3']));
        $getEntries->expects('execute')
            ->with(['_all:tag:empty:entries'])
            ->returns(new LazyCollection([]));

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_all:tag:people:entries', 'prefix:person-1', 'prefix:person-2'], ['person-1', 'person-2'])
            ->returns(1)
            ->ordered();
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_all:tag:artist:entries', 'prefix:person-2', 'prefix:person-3'], ['person-2', 'person-3'])
            ->returns(1)
            ->ordered();

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:people:entries', '_all:tag:artist:entries', '_all:tag:empty:entries']);
    }

    public function testFlushWithNoEntriesDoesNotDeleteTagSets(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return empty collection
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection([]));

        $connection->client()->expects('del')->never();
        $connection->expects('evalWithShaCache')->never();

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushChunksLargeEntrySets(): void
    {
        $connection = $this->mockConnection();

        // Create more than CHUNK_SIZE (1000) entries
        $entries = [];
        for ($i = 1; $i <= 1500; ++$i) {
            $entries[] = "key{$i}";
        }

        // Mock GetEntries to return many cache keys
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection($entries));

        // First chunk: 1000 entries.
        $firstChunkArgs = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunkArgs[] = "prefix:key{$i}";
        }
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_all:tag:users:entries', ...$firstChunkArgs], array_slice($entries, 0, 1000))
            ->returns(1);

        // Second chunk: 500 entries.
        $secondChunkArgs = [];
        for ($i = 1001; $i <= 1500; ++$i) {
            $secondChunkArgs[] = "prefix:key{$i}";
        }
        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_all:tag:users:entries', ...$secondChunkArgs], array_slice($entries, 1000))
            ->returns(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushReleasesScanConnectionsBeforeCheckingOutEachDeletionChunk(): void
    {
        $entries = [];

        for ($i = 1; $i <= 1001; ++$i) {
            $entries["key{$i}"] = 1;
        }

        $client = new FakeRedisClient(
            zScanResults: [
                'prefix:_all:tag:users:entries' => [['members' => $entries, 'iterator' => 0]],
            ],
            evalShaResults: [1, 1],
        );
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($client)->shouldTransform(false);

        $firstChunk = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunk[] = "prefix:key{$i}";
        }

        $active = false;
        $proxy = Double::for(RedisProxy::class)->strict();
        $proxy->allows('isQueueing')->returns(false);
        $proxy->expects('isCluster')->returns(false);
        $proxy->expects('withConnection')
            ->times(3)
            ->with(Argument::type('callable'), false)
            ->resolves(function (callable $callback) use ($connection, &$active) {
                $this->assertFalse($active);
                $active = true;

                try {
                    return $callback($connection);
                } finally {
                    $active = false;
                }
            });

        $redis = Double::for(RedisFactory::class)->strict();
        $redis->expects('connection')
            ->times(4)
            ->with('default')
            ->returns($proxy);

        $store = new RedisStore($redis, 'prefix:', 'default');
        $context = $store->getContext();
        $operation = new Flush($context, new GetEntries($context));

        $operation->execute(['_all:tag:users:entries']);

        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
        $calls = $client->getEvalShaCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(
            ['prefix:_all:tag:users:entries', ...$firstChunk, ...array_slice(array_keys($entries), 0, 1000)],
            $calls[0]['args'],
        );
        $this->assertSame(1001, $calls[0]['num_keys']);
        $this->assertSame(['prefix:_all:tag:users:entries', 'prefix:key1001', 'key1001'], $calls[1]['args']);
        $this->assertSame(2, $calls[1]['num_keys']);
    }

    public function testFlushUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return cache keys
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection(['mykey']));

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['custom_prefix:_all:tag:users:entries', 'custom_prefix:mykey'], ['mykey'])
            ->returns(1);

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushWithEmptyTagIds(): void
    {
        $connection = $this->mockConnection();

        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')->never();

        $connection->client()->expects('del')->never();
        $connection->expects('evalWithShaCache')->never();

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute([]);
    }

    public function testFlushTagKeyFormat(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->returns(new LazyCollection(['key']));

        $connection->expects('evalWithShaCache')
            ->with(Argument::type('string'), ['prefix:_all:tag:my-special-tag:entries', 'prefix:key'], ['key'])
            ->returns(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:my-special-tag:entries']);
    }

    public function testFlushInClusterModeUsesSequentialDel(): void
    {
        [$store, $client] = $this->createClusterStore();

        // Mock GetEntries to return cache keys
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection(['key1', 'key2']));

        $client->expects('zrem')
            ->with('prefix:_all:tag:users:entries', 'key1', 'key2')
            ->returns(2)->ordered();

        $client->expects('del')
            ->with('prefix:key1', 'prefix:key2')
            ->returns(2)->ordered();

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushInClusterModeChunksLargeSets(): void
    {
        [$store, $client] = $this->createClusterStore();

        // Create more than CHUNK_SIZE (1000) entries
        $entries = [];
        for ($i = 1; $i <= 1500; ++$i) {
            $entries[] = "key{$i}";
        }

        // Mock GetEntries to return many cache keys
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection($entries));

        // First chunk: 1000 entries (sequential DEL)
        $firstChunkArgs = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunkArgs[] = "prefix:key{$i}";
        }
        $client->expects('zrem')
            ->with('prefix:_all:tag:users:entries', ...array_slice($entries, 0, 1000))
            ->returns(1000)->ordered();
        $client->expects('del')
            ->with(...$firstChunkArgs)
            ->returns(1000)->ordered();

        // Second chunk: 500 entries (sequential DEL)
        $secondChunkArgs = [];
        for ($i = 1001; $i <= 1500; ++$i) {
            $secondChunkArgs[] = "prefix:key{$i}";
        }
        $client->expects('zrem')
            ->with('prefix:_all:tag:users:entries', ...array_slice($entries, 1000))
            ->returns(500)->ordered();
        $client->expects('del')
            ->with(...$secondChunkArgs)
            ->returns(500)->ordered();

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushInClusterModeWithMultipleTags(): void
    {
        [$store, $client] = $this->createClusterStore();

        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection(['user_key1', 'shared_key']));
        $getEntries->expects('execute')
            ->with(['_all:tag:posts:entries'])
            ->returns(new LazyCollection(['shared_key', 'post_key1']));

        $client->expects('zrem')
            ->with('prefix:_all:tag:users:entries', 'user_key1', 'shared_key')
            ->returns(2)->ordered();
        $client->expects('del')
            ->with('prefix:user_key1', 'prefix:shared_key')
            ->returns(2)->ordered();
        $client->expects('zrem')
            ->with('prefix:_all:tag:posts:entries', 'shared_key', 'post_key1')
            ->returns(2)->ordered();
        $client->expects('del')
            ->with('prefix:shared_key', 'prefix:post_key1')
            ->returns(1)->ordered();

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries']);
    }

    public function testFlushInClusterModeWithNoEntries(): void
    {
        [$store, $client] = $this->createClusterStore();

        // Mock GetEntries to return empty collection
        $getEntries = Double::for(GetEntries::class)->strict();
        $getEntries->expects('execute')
            ->with(['_all:tag:users:entries'])
            ->returns(new LazyCollection([]));

        $client->expects('del')->never();
        $client->expects('zrem')->never();

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries']);
    }
}
