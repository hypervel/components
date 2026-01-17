<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Hypervel\Tests\Redis\Stub\FakeRedisClient;

/**
 * Tests for the GetTaggedKeys operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class GetTaggedKeysTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testGetTaggedKeysUsesHkeysForSmallHashes(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Small hash (below threshold) uses HKEYS
        $client->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(5);
        $client->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['key1', 'key2', 'key3']);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $keys = iterator_to_array($redis->anyTagOps()->getTaggedKeys()->execute('users'));

        $this->assertSame(['key1', 'key2', 'key3'], $keys);
    }

    /**
     * @test
     */
    public function testGetTaggedKeysUsesHscanForLargeHashes(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Large hash (above threshold of 1000) uses HSCAN
        $client->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(5000);

        // HSCAN returns key-value pairs, iterator updates by reference
        $client->shouldReceive('hscan')
            ->once()
            ->withArgs(function ($key, &$iterator, $pattern, $count) {
                $iterator = 0; // Done after first iteration
                return true;
            })
            ->andReturn(['key1' => '1', 'key2' => '1', 'key3' => '1']);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $keys = iterator_to_array($redis->anyTagOps()->getTaggedKeys()->execute('users'));

        $this->assertSame(['key1', 'key2', 'key3'], $keys);
    }

    /**
     * @test
     */
    public function testGetTaggedKeysReturnsEmptyForNonExistentTag(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:nonexistent:entries')
            ->andReturn(0);
        $client->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:nonexistent:entries')
            ->andReturn([]);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $keys = iterator_to_array($redis->anyTagOps()->getTaggedKeys()->execute('nonexistent'));

        $this->assertSame([], $keys);
    }

    /**
     * @test
     *
     * Verifies that HSCAN correctly handles multiple batches with per-batch connection checkout.
     * The iterator must be passed by reference correctly across withConnection() calls.
     *
     * Uses FakeRedisClient instead of Mockery because Mockery doesn't properly propagate
     * modifications to reference parameters (like &$iterator) back to the caller.
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
        $this->assertSame(3, $fakeClient->getHScanCallCount());
    }
}
