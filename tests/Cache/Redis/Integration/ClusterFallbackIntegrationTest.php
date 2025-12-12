<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hyperf\Redis\Pool\PoolFactory;
use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\RedisStore;
use Hypervel\Support\Facades\Cache;

/**
 * Custom StoreContext that simulates cluster mode.
 *
 * This allows testing cluster code paths (PHP fallbacks instead of Lua)
 * against a real single-instance Redis server.
 */
class ClusterModeStoreContext extends StoreContext
{
    public function isCluster(): bool
    {
        return true;
    }
}

/**
 * Custom RedisStore that uses cluster-mode context.
 */
class ClusterModeRedisStore extends RedisStore
{
    private ?ClusterModeStoreContext $clusterContext = null;

    public function getContext(): StoreContext
    {
        return $this->clusterContext ??= new ClusterModeStoreContext(
            $this->getPoolFactoryInternal(),
            $this->connection,
            $this->getPrefix(),
            $this->getTagMode(),
        );
    }

    public function getPoolFactoryInternal(): PoolFactory
    {
        return parent::getPoolFactory();
    }
}

/**
 * Integration tests for cluster mode code paths (PHP fallbacks).
 *
 * These tests verify that when isCluster() returns true, the PHP fallback
 * code paths work correctly. This is important because:
 * - RedisCluster does not support Lua scripts across slots
 * - RedisCluster does not support pipeline() method
 * - Operations must use sequential commands or multi() instead
 *
 * We test against real single-instance Redis with isCluster() mocked to true.
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class ClusterFallbackIntegrationTest extends RedisCacheIntegrationTestCase
{
    private ?ClusterModeRedisStore $clusterStore = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create cluster-mode store using the same factory as the real store
        $factory = $this->app->get(\Hyperf\Redis\RedisFactory::class);
        $realStore = Cache::store('redis')->getStore();

        $this->clusterStore = new ClusterModeRedisStore(
            $factory,
            $realStore->getPrefix(),
            'default',
        );
        $this->clusterStore->setTagMode('any');
    }

    protected function tearDown(): void
    {
        $this->clusterStore = null;
        parent::tearDown();
    }

    /**
     * Helper to get the cluster-mode tagged cache.
     */
    private function clusterTags(array $tags): AnyTaggedCache
    {
        return new AnyTaggedCache(
            $this->clusterStore,
            new AnyTagSet($this->clusterStore, $tags)
        );
    }

    // =========================================================================
    // PUT WITH TAGS - PHP FALLBACK
    // =========================================================================

    public function testClusterModePutWithTags(): void
    {
        $this->clusterTags(['cluster-tag'])->put('cluster-put', 'value', 60);

        $this->assertSame('value', $this->clusterStore->get('cluster-put'));

        // Verify tag tracking exists
        $this->assertTrue($this->anyModeTagHasEntry('cluster-tag', 'cluster-put'));
    }

    public function testClusterModePutWithMultipleTags(): void
    {
        $this->clusterTags(['tag1', 'tag2', 'tag3'])->put('multi-tag-item', 'value', 60);

        $this->assertSame('value', $this->clusterStore->get('multi-tag-item'));

        // All tags should have the entry
        $this->assertTrue($this->anyModeTagHasEntry('tag1', 'multi-tag-item'));
        $this->assertTrue($this->anyModeTagHasEntry('tag2', 'multi-tag-item'));
        $this->assertTrue($this->anyModeTagHasEntry('tag3', 'multi-tag-item'));
    }

    // =========================================================================
    // ADD WITH TAGS - PHP FALLBACK
    // =========================================================================

    public function testClusterModeAddWithTagsSucceeds(): void
    {
        $result = $this->clusterTags(['cluster-tag'])->add('cluster-add', 'value', 60);

        $this->assertTrue($result);
        $this->assertSame('value', $this->clusterStore->get('cluster-add'));

        // Verify tag tracking
        $this->assertTrue($this->anyModeTagHasEntry('cluster-tag', 'cluster-add'));
    }

    public function testClusterModeAddWithTagsFailsForExistingKey(): void
    {
        // First add succeeds
        $result1 = $this->clusterTags(['cluster-tag'])->add('cluster-add-fail', 'first', 60);
        $this->assertTrue($result1);

        // Second add fails
        $result2 = $this->clusterTags(['cluster-tag'])->add('cluster-add-fail', 'second', 60);
        $this->assertFalse($result2);

        // Value should remain the first
        $this->assertSame('first', $this->clusterStore->get('cluster-add-fail'));
    }

    // =========================================================================
    // FOREVER WITH TAGS - PHP FALLBACK
    // =========================================================================

    public function testClusterModeForeverWithTags(): void
    {
        $this->clusterTags(['cluster-tag'])->forever('cluster-forever', 'forever-value');

        $this->assertSame('forever-value', $this->clusterStore->get('cluster-forever'));

        // Verify no TTL (forever)
        $prefix = $this->getCachePrefix();
        $ttl = $this->redis()->ttl($prefix . 'cluster-forever');
        $this->assertEquals(-1, $ttl);
    }

    // =========================================================================
    // INCREMENT/DECREMENT WITH TAGS - PHP FALLBACK
    // =========================================================================

    public function testClusterModeIncrementWithTags(): void
    {
        $this->clusterTags(['cluster-tag'])->put('cluster-incr', 10, 60);

        $newValue = $this->clusterTags(['cluster-tag'])->increment('cluster-incr');

        $this->assertEquals(11, $newValue);
        $this->assertEquals(11, $this->clusterStore->get('cluster-incr'));
    }

    public function testClusterModeIncrementWithTagsByAmount(): void
    {
        $this->clusterTags(['cluster-tag'])->put('cluster-incr-by', 10, 60);

        $newValue = $this->clusterTags(['cluster-tag'])->increment('cluster-incr-by', 5);

        $this->assertEquals(15, $newValue);
        $this->assertEquals(15, $this->clusterStore->get('cluster-incr-by'));
    }

    public function testClusterModeDecrementWithTags(): void
    {
        $this->clusterTags(['cluster-tag'])->put('cluster-decr', 10, 60);

        $newValue = $this->clusterTags(['cluster-tag'])->decrement('cluster-decr');

        $this->assertEquals(9, $newValue);
        $this->assertEquals(9, $this->clusterStore->get('cluster-decr'));
    }

    public function testClusterModeDecrementWithTagsByAmount(): void
    {
        $this->clusterTags(['cluster-tag'])->put('cluster-decr-by', 10, 60);

        $newValue = $this->clusterTags(['cluster-tag'])->decrement('cluster-decr-by', 3);

        $this->assertEquals(7, $newValue);
        $this->assertEquals(7, $this->clusterStore->get('cluster-decr-by'));
    }

    // =========================================================================
    // PUTMANY WITH TAGS - PHP FALLBACK
    // =========================================================================

    public function testClusterModePutManyWithTags(): void
    {
        $this->clusterTags(['cluster-tag'])->putMany([
            'cluster-k1' => 'v1',
            'cluster-k2' => 'v2',
            'cluster-k3' => 'v3',
        ], 60);

        $this->assertSame('v1', $this->clusterStore->get('cluster-k1'));
        $this->assertSame('v2', $this->clusterStore->get('cluster-k2'));
        $this->assertSame('v3', $this->clusterStore->get('cluster-k3'));

        // All should have tag tracking
        $this->assertTrue($this->anyModeTagHasEntry('cluster-tag', 'cluster-k1'));
        $this->assertTrue($this->anyModeTagHasEntry('cluster-tag', 'cluster-k2'));
        $this->assertTrue($this->anyModeTagHasEntry('cluster-tag', 'cluster-k3'));
    }

    // =========================================================================
    // FLUSH - PHP FALLBACK
    // =========================================================================

    public function testClusterModeFlush(): void
    {
        $this->clusterTags(['flush-tag'])->put('flush-item1', 'value1', 60);
        $this->clusterTags(['flush-tag'])->put('flush-item2', 'value2', 60);

        // Verify items exist
        $this->assertSame('value1', $this->clusterStore->get('flush-item1'));
        $this->assertSame('value2', $this->clusterStore->get('flush-item2'));

        // Flush
        $this->clusterTags(['flush-tag'])->flush();

        // Items should be gone
        $this->assertNull($this->clusterStore->get('flush-item1'));
        $this->assertNull($this->clusterStore->get('flush-item2'));
    }

    public function testClusterModeFlushMultipleTags(): void
    {
        $this->clusterTags(['tag-a'])->put('item-a', 'value-a', 60);
        $this->clusterTags(['tag-b'])->put('item-b', 'value-b', 60);
        $this->clusterTags(['tag-c'])->put('item-c', 'value-c', 60);

        // Flush tag-a and tag-b together
        $this->clusterTags(['tag-a', 'tag-b'])->flush();

        // Items with tag-a or tag-b should be gone
        $this->assertNull($this->clusterStore->get('item-a'));
        $this->assertNull($this->clusterStore->get('item-b'));

        // Item with only tag-c should remain
        $this->assertSame('value-c', $this->clusterStore->get('item-c'));
    }

    // =========================================================================
    // TAG REPLACEMENT - PHP FALLBACK
    // =========================================================================

    public function testClusterModeTagReplacement(): void
    {
        // Initial: item with tag1
        $this->clusterTags(['tag1'])->put('replace-test', 10, 60);

        // Update: item with tag2 (replaces tag1)
        $this->clusterTags(['tag2'])->increment('replace-test', 1);

        // Verify value
        $this->assertEquals(11, $this->clusterStore->get('replace-test'));

        // Flush old tag should NOT remove the item
        $this->clusterTags(['tag1'])->flush();
        $this->assertEquals(11, $this->clusterStore->get('replace-test'));

        // Flush new tag SHOULD remove the item
        $this->clusterTags(['tag2'])->flush();
        $this->assertNull($this->clusterStore->get('replace-test'));
    }

    public function testClusterModeTagReplacementOnPut(): void
    {
        // Initial: item with tag1
        $this->clusterTags(['tag1'])->put('replace-put', 'original', 60);
        $this->assertTrue($this->anyModeTagHasEntry('tag1', 'replace-put'));

        // Update: same key with different tag
        $this->clusterTags(['tag2'])->put('replace-put', 'updated', 60);
        $this->assertTrue($this->anyModeTagHasEntry('tag2', 'replace-put'));

        // Value should be updated
        $this->assertSame('updated', $this->clusterStore->get('replace-put'));

        // Old tag should no longer have the entry (reverse index cleaned up)
        // Note: The old tag hash may still have orphaned entry until prune runs
    }

    // =========================================================================
    // REMEMBER - PHP FALLBACK
    // =========================================================================

    public function testClusterModeRememberMiss(): void
    {
        $value = $this->clusterTags(['remember-tag'])->remember('remember-miss', 60, fn () => 'computed');

        $this->assertSame('computed', $value);
        $this->assertSame('computed', $this->clusterStore->get('remember-miss'));
        $this->assertTrue($this->anyModeTagHasEntry('remember-tag', 'remember-miss'));
    }

    public function testClusterModeRememberHit(): void
    {
        // Pre-populate
        $this->clusterTags(['remember-tag'])->put('remember-hit', 'existing', 60);

        $callbackCalled = false;
        $value = $this->clusterTags(['remember-tag'])->remember('remember-hit', 60, function () use (&$callbackCalled) {
            $callbackCalled = true;
            return 'computed';
        });

        $this->assertSame('existing', $value);
        $this->assertFalse($callbackCalled);
    }

    public function testClusterModeRememberForever(): void
    {
        $value = $this->clusterTags(['remember-tag'])->rememberForever('remember-forever', fn () => 'forever-value');

        $this->assertSame('forever-value', $value);
        $this->assertSame('forever-value', $this->clusterStore->get('remember-forever'));

        // Verify no TTL
        $prefix = $this->getCachePrefix();
        $ttl = $this->redis()->ttl($prefix . 'remember-forever');
        $this->assertEquals(-1, $ttl);
    }

    // =========================================================================
    // COMPLEX SCENARIOS - PHP FALLBACK
    // =========================================================================

    public function testClusterModeMixedOperations(): void
    {
        // Various operations
        $this->clusterTags(['mixed'])->put('put-item', 'put-value', 60);
        $this->clusterTags(['mixed'])->forever('forever-item', 'forever-value');
        $this->clusterTags(['mixed'])->put('counter', 0, 60);
        $this->clusterTags(['mixed'])->increment('counter', 10);
        $this->clusterTags(['mixed'])->decrement('counter', 3);
        $this->clusterTags(['mixed'])->add('add-item', 'add-value', 60);

        // Verify all operations worked
        $this->assertSame('put-value', $this->clusterStore->get('put-item'));
        $this->assertSame('forever-value', $this->clusterStore->get('forever-item'));
        $this->assertEquals(7, $this->clusterStore->get('counter'));
        $this->assertSame('add-value', $this->clusterStore->get('add-item'));

        // Flush should remove all
        $this->clusterTags(['mixed'])->flush();

        $this->assertNull($this->clusterStore->get('put-item'));
        $this->assertNull($this->clusterStore->get('forever-item'));
        $this->assertNull($this->clusterStore->get('counter'));
        $this->assertNull($this->clusterStore->get('add-item'));
    }

    public function testClusterModeOverlappingTags(): void
    {
        // Items with overlapping tags
        $this->clusterTags(['shared', 'unique-1'])->put('item-1', 'value-1', 60);
        $this->clusterTags(['shared', 'unique-2'])->put('item-2', 'value-2', 60);
        $this->clusterTags(['unique-3'])->put('item-3', 'value-3', 60);

        // Flush shared tag
        $this->clusterTags(['shared'])->flush();

        // Items with shared tag should be gone
        $this->assertNull($this->clusterStore->get('item-1'));
        $this->assertNull($this->clusterStore->get('item-2'));

        // Item without shared tag should remain
        $this->assertSame('value-3', $this->clusterStore->get('item-3'));
    }

    public function testClusterModeLargeTagSet(): void
    {
        // Create items with many tags
        $tags = [];
        for ($i = 0; $i < 10; ++$i) {
            $tags[] = "large-tag-{$i}";
        }

        $this->clusterTags($tags)->put('large-tag-item', 'value', 60);

        $this->assertSame('value', $this->clusterStore->get('large-tag-item'));

        // All tags should have the entry
        foreach ($tags as $tag) {
            $this->assertTrue($this->anyModeTagHasEntry($tag, 'large-tag-item'));
        }

        // Flushing any single tag should remove the item
        $this->clusterTags(['large-tag-5'])->flush();
        $this->assertNull($this->clusterStore->get('large-tag-item'));
    }
}
