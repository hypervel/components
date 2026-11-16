<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;

/**
 * Tests for the GetTaggedKeys operation (union tags).
 */
class GetTaggedKeysTest extends RedisCacheTestCase
{
    public function testGetTaggedKeysUsesHkeysForSmallHashes(): void
    {
        $connection = $this->mockConnection();

        // Small hash (below threshold) uses HKEYS
        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(5);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['key1', 'key2', 'key3']);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $keys = iterator_to_array($redis->anyTagOps()->getTaggedKeys()->execute('users'));

        $this->assertSame(['key1', 'key2', 'key3'], $keys);
    }

    public function testGetTaggedKeysUsesHscanForLargeHashes(): void
    {
        $tagKey = 'prefix:_any:tag:users:entries';
        $client = new FakeRedisClient(
            hLenResults: [$tagKey => 5000],
            hScanResults: [
                $tagKey => [['fields' => ['key1' => '1', 'key2' => '1', 'key3' => '1'], 'iterator' => 0]],
            ],
        );
        $store = $this->createStoreWithFakeClient($client, tagMode: 'any');

        $keys = iterator_to_array($store->anyTagOps()->getTaggedKeys()->execute('users'));

        $this->assertSame(['key1', 'key2', 'key3'], $keys);
        $this->assertSame([$tagKey], $client->getHLenCalls());
        $this->assertSame([
            ['key' => $tagKey, 'iterator' => null, 'pattern' => null, 'count' => 1000],
        ], $client->getHScanCalls());
    }

    public function testGetTaggedKeysReturnsEmptyForNonExistentTag(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:nonexistent:entries')
            ->returns(0);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:nonexistent:entries')
            ->returns([]);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $keys = iterator_to_array($redis->anyTagOps()->getTaggedKeys()->execute('nonexistent'));

        $this->assertSame([], $keys);
    }

    /**
     * Verifies that HSCAN correctly handles multiple batches with per-batch connection checkout.
     * The iterator must be passed by reference correctly across withConnection() calls.
     *
     * A concrete fake preserves the scan cursor changes that generated doubles cannot return.
     */
    public function testGetTaggedKeysHandlesMultipleHscanBatches(): void
    {
        $tagKey = 'prefix:_any:tag:users:entries';

        // FakeRedisClient properly handles &$iterator reference parameter
        $fakeClient = new FakeRedisClient(
            hLenResults: [$tagKey => 5000], // Large hash triggers HSCAN path
            hScanResults: [
                $tagKey => [
                    // First batch: iterator -> 100 (more to come)
                    ['fields' => ['key1' => '1', 'key2' => '1'], 'iterator' => 100],
                    // Second batch: iterator -> 200 (more to come)
                    ['fields' => ['key3' => '1', 'key4' => '1'], 'iterator' => 200],
                    // Third batch: iterator -> 0 (done)
                    ['fields' => ['key5' => '1'], 'iterator' => 0],
                ],
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient, tagMode: 'any');

        $keys = iterator_to_array($store->anyTagOps()->getTaggedKeys()->execute('users'));

        // Should have all keys from all 3 batches
        $this->assertSame(['key1', 'key2', 'key3', 'key4', 'key5'], $keys);

        // Verify all 3 HSCAN batches were called
        $this->assertSame([null, 100, 200], array_column($fakeClient->getHScanCalls(), 'iterator'));
    }

    public function testGetTaggedKeysContinuesAfterAnEmptyNonterminalPage(): void
    {
        $tagKey = 'prefix:_any:tag:users:entries';
        $fakeClient = new FakeRedisClient(
            hLenResults: [$tagKey => 5000],
            hScanResults: [
                $tagKey => [
                    ['fields' => [], 'iterator' => 100],
                    ['fields' => ['key1' => '1'], 'iterator' => 0],
                ],
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient, tagMode: 'any');

        $keys = iterator_to_array($store->anyTagOps()->getTaggedKeys()->execute('users'));

        $this->assertSame(['key1'], $keys);
        $this->assertSame(2, $fakeClient->getHScanCallCount());
    }
}
