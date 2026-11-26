<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

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
 * Tests for the GetEntries operation.
 */
class GetEntriesTest extends RedisCacheTestCase
{
    public function testGetEntriesReturnsLazyCollection(): void
    {
        $client = new FakeRedisClient(zScanResults: [
            'prefix:_all:tag:users:entries' => [
                ['members' => ['key1' => 1, 'key2' => 2], 'iterator' => 0],
            ],
        ]);

        $store = $this->createStoreWithFakeClient($client);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertInstanceOf(LazyCollection::class, $entries);
        $this->assertSame(['key1', 'key2'], $entries->all());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
    }

    public function testGetEntriesWithEmptyTagReturnsEmptyCollection(): void
    {
        $client = new FakeRedisClient(zScanResults: [
            'prefix:_all:tag:users:entries' => [
                ['members' => [], 'iterator' => 0],
            ],
        ]);

        $store = $this->createStoreWithFakeClient($client);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame([], $entries->all());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
    }

    public function testGetEntriesWithMultipleTags(): void
    {
        $client = new FakeRedisClient(zScanResults: [
            'prefix:_all:tag:users:entries' => [
                ['members' => ['user_key1' => 1, 'user_key2' => 2], 'iterator' => 0],
            ],
            'prefix:_all:tag:posts:entries' => [
                ['members' => ['post_key1' => 1], 'iterator' => 0],
            ],
        ]);

        $store = $this->createStoreWithFakeClient($client);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries']);

        // Should combine entries from both tags
        $this->assertSame(['user_key1', 'user_key2', 'post_key1'], $entries->all());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
            ['key' => 'prefix:_all:tag:posts:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
    }

    public function testGetEntriesHandlesFalseScanResult(): void
    {
        $connection = $this->mockConnection();
        // Native zScan returns false when no members are available.
        $connection->client()->expects('zScan')
            ->with('prefix:_all:tag:users:entries', null, '*', 1000)
            ->returns(false);

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame([], $entries->all());
    }

    public function testGetEntriesWithEmptyTagIdsArrayReturnsEmptyCollection(): void
    {
        $connection = $this->mockConnection();
        // No zScan calls should be made
        $connection->expects('zScan')->never();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute([]);

        $this->assertSame([], $entries->all());
    }

    public function testGetEntriesUsesCorrectPrefix(): void
    {
        $client = new FakeRedisClient(zScanResults: [
            'custom_prefix:_all:tag:users:entries' => [
                ['members' => ['key1' => 1], 'iterator' => 0],
            ],
        ]);

        $store = $this->createStoreWithFakeClient($client, 'custom_prefix:');
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame(['key1'], $entries->all());
        $this->assertSame([
            ['key' => 'custom_prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
    }

    public function testGetEntriesHandlesPaginatedResults(): void
    {
        $client = new FakeRedisClient(zScanResults: [
            'prefix:_all:tag:users:entries' => [
                ['members' => ['key1' => 1, 'key2' => 2], 'iterator' => 123],
                ['members' => ['key3' => 3], 'iterator' => 0],
            ],
        ]);

        $store = $this->createStoreWithFakeClient($client);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame(['key1', 'key2', 'key3'], $entries->all());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => 123, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
    }

    public function testGetEntriesContinuesAfterAnEmptyNonterminalPageAndChecksOutPerPage(): void
    {
        $client = new FakeRedisClient(zScanResults: [
            'prefix:_all:tag:users:entries' => [
                ['members' => [], 'iterator' => 123],
                ['members' => ['key1' => 1], 'iterator' => 0],
            ],
        ]);

        $connection = (new PhpRedisConnectionStub)->setActiveConnection($client)->shouldTransform(false);
        $proxy = Double::for(RedisProxy::class)->strict();
        $proxy->allows('isQueueing')->returns(false);
        $proxy->expects('withConnection')
            ->times(2)
            ->with(Argument::type('callable'), false)
            ->resolves(fn (callable $callback) => $callback($connection));

        $redis = Double::for(RedisFactory::class)->strict();
        $redis->expects('connection')
            ->times(2)
            ->with('default')
            ->returns($proxy);

        $store = new RedisStore($redis, 'prefix:', 'default');
        $operation = new GetEntries($store->getContext());

        $this->assertSame(['key1'], $operation->execute(['_all:tag:users:entries'])->all());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => 123, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
    }

    /**
     * If the same key exists in multiple tags, it will appear multiple times in the result.
     * This is intentional - the Flush operation handles this gracefully
     * (deleting a non-existent key is a no-op).
     */
    public function testGetEntriesDoesNotDeduplicateAcrossTags(): void
    {
        $client = new FakeRedisClient(zScanResults: [
            'prefix:_all:tag:users:entries' => [
                ['members' => ['shared_key' => 1, 'user_only' => 2], 'iterator' => 0],
            ],
            'prefix:_all:tag:posts:entries' => [
                ['members' => ['shared_key' => 1, 'post_only' => 2], 'iterator' => 0],
            ],
        ]);

        $store = $this->createStoreWithFakeClient($client);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries']);

        // 'shared_key' appears twice - once from each tag
        $this->assertSame(['shared_key', 'user_only', 'shared_key', 'post_only'], $entries->all());
        $this->assertSame([
            ['key' => 'prefix:_all:tag:users:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
            ['key' => 'prefix:_all:tag:posts:entries', 'iterator' => null, 'pattern' => '*', 'count' => 1000],
        ], $client->getZScanCalls());
    }
}
