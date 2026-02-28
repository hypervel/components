<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisFactory;

/**
 * Integration tests for prefix handling with different configurations.
 *
 * Tests that cache operations work correctly with various cache prefixes
 * and that different prefixes provide proper isolation.
 *
 * @internal
 * @coversNothing
 */
class PrefixHandlingIntegrationTest extends RedisCacheIntegrationTestCase
{
    /**
     * Create a store with specific cache prefix (uses any tag mode).
     */
    private function createStoreWithPrefix(string $cachePrefix): RedisStore
    {
        $this->skipIfAnyTagModeUnsupported();

        $factory = $this->app->make(RedisFactory::class);
        $store = new RedisStore($factory, $cachePrefix, 'default');
        $store->setTagMode(TagMode::Any);

        return $store;
    }

    // =========================================================================
    // BASIC OPERATIONS WITH DIFFERENT PREFIXES
    // =========================================================================

    public function testPutGetWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('custom:');

        $store->put('test_key', 'test_value', 60);
        $this->assertSame('test_value', $store->get('test_key'));
    }

    public function testPutGetWithEmptyPrefix(): void
    {
        $store = $this->createStoreWithPrefix('');

        $store->put('test_key', 'test_value', 60);
        $this->assertSame('test_value', $store->get('test_key'));
    }

    public function testPutGetWithLongPrefix(): void
    {
        $store = $this->createStoreWithPrefix('very:long:nested:prefix:structure:');

        $store->put('test_key', 'test_value', 60);
        $this->assertSame('test_value', $store->get('test_key'));
    }

    public function testForgetWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('forget_test:');

        $store->put('key_to_forget', 'value', 60);
        $this->assertSame('value', $store->get('key_to_forget'));

        $store->forget('key_to_forget');
        $this->assertNull($store->get('key_to_forget'));
    }

    // =========================================================================
    // PREFIX ISOLATION - DIFFERENT PREFIXES ARE ISOLATED
    // =========================================================================

    public function testDifferentPrefixesAreIsolated(): void
    {
        $store1 = $this->createStoreWithPrefix('app1:');
        $store2 = $this->createStoreWithPrefix('app2:');

        // Same key name in different stores
        $store1->put('shared_key', 'value_from_app1', 60);
        $store2->put('shared_key', 'value_from_app2', 60);

        // Each store sees only its own value
        $this->assertSame('value_from_app1', $store1->get('shared_key'));
        $this->assertSame('value_from_app2', $store2->get('shared_key'));
    }

    public function testForgetOnlyAffectsOwnPrefix(): void
    {
        $store1 = $this->createStoreWithPrefix('app1:');
        $store2 = $this->createStoreWithPrefix('app2:');

        $store1->put('key', 'value1', 60);
        $store2->put('key', 'value2', 60);

        // Forget from store1 only affects store1
        $store1->forget('key');

        $this->assertNull($store1->get('key'));
        $this->assertSame('value2', $store2->get('key'));
    }

    public function testMultipleStoresWithDifferentPrefixes(): void
    {
        $stores = [
            'a' => $this->createStoreWithPrefix('prefix_a:'),
            'b' => $this->createStoreWithPrefix('prefix_b:'),
            'c' => $this->createStoreWithPrefix('prefix_c:'),
        ];

        // Each store writes to same key name
        foreach ($stores as $name => $store) {
            $store->put('common_key', "value_from_{$name}", 60);
        }

        // Each store reads its own value
        foreach ($stores as $name => $store) {
            $this->assertSame("value_from_{$name}", $store->get('common_key'));
        }
    }

    // =========================================================================
    // TAGGED OPERATIONS WITH DIFFERENT PREFIXES
    // =========================================================================

    public function testTaggedOperationsWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('tagged_app:');
        $tagged = new AnyTaggedCache($store, new AnyTagSet($store, ['my_tag']));

        $tagged->put('tagged_item', 'tagged_value', 60);
        $this->assertSame('tagged_value', $store->get('tagged_item'));

        $tagged->flush();
        $this->assertNull($store->get('tagged_item'));
    }

    public function testTaggedOperationsIsolatedByPrefix(): void
    {
        $store1 = $this->createStoreWithPrefix('app1:');
        $store2 = $this->createStoreWithPrefix('app2:');

        $tagged1 = new AnyTaggedCache($store1, new AnyTagSet($store1, ['shared_tag']));
        $tagged2 = new AnyTaggedCache($store2, new AnyTagSet($store2, ['shared_tag']));

        // Same tag name, different stores
        $tagged1->put('item', 'from_app1', 60);
        $tagged2->put('item', 'from_app2', 60);

        // Flush tag in store1 only affects store1
        $tagged1->flush();

        $this->assertNull($store1->get('item'));
        $this->assertSame('from_app2', $store2->get('item'));
    }

    public function testMultipleTagsWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('multi_tag:');
        $tagged = new AnyTaggedCache($store, new AnyTagSet($store, ['tag1', 'tag2', 'tag3']));

        $tagged->put('multi_tagged_item', 'value', 60);
        $this->assertSame('value', $store->get('multi_tagged_item'));

        // Flushing any tag should remove the item
        $singleTag = new AnyTaggedCache($store, new AnyTagSet($store, ['tag2']));
        $singleTag->flush();

        $this->assertNull($store->get('multi_tagged_item'));
    }

    // =========================================================================
    // INCREMENT/DECREMENT WITH DIFFERENT PREFIXES
    // =========================================================================

    public function testIncrementDecrementWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('counter:');

        $store->put('my_counter', 10, 60);

        $newValue = $store->increment('my_counter', 5);
        $this->assertEquals(15, $newValue);

        $newValue = $store->decrement('my_counter', 3);
        $this->assertEquals(12, $newValue);
    }

    public function testIncrementIsolatedByPrefix(): void
    {
        $store1 = $this->createStoreWithPrefix('app1:');
        $store2 = $this->createStoreWithPrefix('app2:');

        $store1->put('counter', 100, 60);
        $store2->put('counter', 200, 60);

        $store1->increment('counter', 10);
        $store2->increment('counter', 20);

        $this->assertEquals(110, $store1->get('counter'));
        $this->assertEquals(220, $store2->get('counter'));
    }

    // =========================================================================
    // PUTMANY WITH DIFFERENT PREFIXES
    // =========================================================================

    public function testPutManyWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('batch:');

        $store->putMany([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], 60);

        $this->assertSame('value1', $store->get('key1'));
        $this->assertSame('value2', $store->get('key2'));
        $this->assertSame('value3', $store->get('key3'));
    }

    public function testManyRetrievalWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('many:');

        $store->putMany([
            'a' => '1',
            'b' => '2',
            'c' => '3',
        ], 60);

        $result = $store->many(['a', 'b', 'c', 'nonexistent']);

        $this->assertSame('1', $result['a']);
        $this->assertSame('2', $result['b']);
        $this->assertSame('3', $result['c']);
        $this->assertNull($result['nonexistent']);
    }

    // =========================================================================
    // SPECIAL CHARACTERS IN PREFIX
    // =========================================================================

    public function testPrefixWithColons(): void
    {
        $store = $this->createStoreWithPrefix('app:v2:prod:');

        $store->put('key', 'value', 60);
        $this->assertSame('value', $store->get('key'));

        $store->forget('key');
        $this->assertNull($store->get('key'));
    }

    public function testPrefixWithNumbers(): void
    {
        $store = $this->createStoreWithPrefix('cache123:');

        $store->put('key', 'value', 60);
        $this->assertSame('value', $store->get('key'));
    }

    public function testPrefixWithUnderscores(): void
    {
        $store = $this->createStoreWithPrefix('my_app_cache_');

        $store->put('key', 'value', 60);
        $this->assertSame('value', $store->get('key'));
    }

    // =========================================================================
    // ADD OPERATION WITH DIFFERENT PREFIXES
    // =========================================================================

    public function testAddWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('add_test:');

        // First add succeeds
        $result = $store->add('unique_key', 'first_value', 60);
        $this->assertTrue($result);
        $this->assertSame('first_value', $store->get('unique_key'));

        // Second add fails
        $result = $store->add('unique_key', 'second_value', 60);
        $this->assertFalse($result);
        $this->assertSame('first_value', $store->get('unique_key'));
    }

    public function testAddIsolatedByPrefix(): void
    {
        $store1 = $this->createStoreWithPrefix('app1:');
        $store2 = $this->createStoreWithPrefix('app2:');

        // Both can add the same key name
        $this->assertTrue($store1->add('key', 'value1', 60));
        $this->assertTrue($store2->add('key', 'value2', 60));

        $this->assertSame('value1', $store1->get('key'));
        $this->assertSame('value2', $store2->get('key'));
    }

    // =========================================================================
    // FOREVER OPERATION WITH DIFFERENT PREFIXES
    // =========================================================================

    public function testForeverWithCustomPrefix(): void
    {
        $store = $this->createStoreWithPrefix('forever:');

        $store->forever('permanent_key', 'permanent_value');
        $this->assertSame('permanent_value', $store->get('permanent_key'));
    }

    public function testForeverIsolatedByPrefix(): void
    {
        $store1 = $this->createStoreWithPrefix('app1:');
        $store2 = $this->createStoreWithPrefix('app2:');

        $store1->forever('key', 'value1');
        $store2->forever('key', 'value2');

        $this->assertSame('value1', $store1->get('key'));
        $this->assertSame('value2', $store2->get('key'));
    }

    // =========================================================================
    // OPT_PREFIX SCENARIOS - ACTUAL KEY VERIFICATION
    // =========================================================================

    /**
     * Create a store with specific OPT_PREFIX and cache prefix (uses any tag mode).
     */
    private function createStoreWithPrefixes(string $optPrefix, string $cachePrefix): RedisStore
    {
        $this->skipIfAnyTagModeUnsupported();

        $connectionName = $this->createRedisConnectionWithPrefix($optPrefix);
        $factory = $this->app->make(RedisFactory::class);
        $store = new RedisStore($factory, $cachePrefix, $connectionName);
        $store->setTagMode(TagMode::Any);

        return $store;
    }

    public function testOptPrefixOnlyNoCachePrefix(): void
    {
        // Create store with OPT_PREFIX only (no cache prefix)
        $store = $this->createStoreWithPrefixes('opt:', '');

        $store->put('test_key', 'test_value', 60);
        $this->assertSame('test_value', $store->get('test_key'));

        // Verify actual key structure using raw client
        $rawClient = $this->rawRedisClientWithoutPrefix();
        $this->assertTrue($rawClient->exists('opt:test_key') > 0);
        $rawClient->close();
    }

    public function testBothOptPrefixAndCachePrefix(): void
    {
        // Create store with both OPT_PREFIX and cache prefix
        $store = $this->createStoreWithPrefixes('opt:', 'cache:');

        $store->put('test_key', 'test_value', 60);
        $this->assertSame('test_value', $store->get('test_key'));

        // Verify actual key structure: OPT_PREFIX + cache prefix + key
        $rawClient = $this->rawRedisClientWithoutPrefix();
        $this->assertTrue($rawClient->exists('opt:cache:test_key') > 0);
        $rawClient->close();
    }

    public function testNoOptPrefixCachePrefixOnly(): void
    {
        // Create store with no OPT_PREFIX, only cache prefix
        $store = $this->createStoreWithPrefixes('', 'cache:');

        $store->put('test_key', 'test_value', 60);
        $this->assertSame('test_value', $store->get('test_key'));

        // Verify actual key structure: cache prefix + key only
        $rawClient = $this->rawRedisClientWithoutPrefix();
        $this->assertTrue($rawClient->exists('cache:test_key') > 0);
        $rawClient->close();
    }

    public function testNoPrefixesAtAll(): void
    {
        // Create store with no prefixes at all
        $store = $this->createStoreWithPrefixes('', '');

        $store->put('test_key', 'test_value', 60);
        $this->assertSame('test_value', $store->get('test_key'));

        // Verify actual key structure: just the key
        $rawClient = $this->rawRedisClientWithoutPrefix();
        $this->assertTrue($rawClient->exists('test_key') > 0);
        $rawClient->close();
    }

    public function testOptPrefixIsolation(): void
    {
        // Create two stores with different OPT_PREFIX
        $store1 = $this->createStoreWithPrefixes('app1:', 'cache:');
        $store2 = $this->createStoreWithPrefixes('app2:', 'cache:');

        $store1->put('shared_key', 'from_app1', 60);
        $store2->put('shared_key', 'from_app2', 60);

        // Each store sees its own value
        $this->assertSame('from_app1', $store1->get('shared_key'));
        $this->assertSame('from_app2', $store2->get('shared_key'));

        // Verify in Redis: different keys
        $rawClient = $this->rawRedisClientWithoutPrefix();
        $this->assertTrue($rawClient->exists('app1:cache:shared_key') > 0);
        $this->assertTrue($rawClient->exists('app2:cache:shared_key') > 0);
        $rawClient->close();
    }

    public function testOptPrefixWithTaggedOperations(): void
    {
        $store = $this->createStoreWithPrefixes('opt:', 'cache:');
        $tagged = new AnyTaggedCache($store, new AnyTagSet($store, ['products']));

        $tagged->put('laptop', 'MacBook', 60);
        $this->assertSame('MacBook', $store->get('laptop'));

        // Verify actual keys in Redis
        $rawClient = $this->rawRedisClientWithoutPrefix();

        // Value key: opt: + cache: + key
        $this->assertTrue($rawClient->exists('opt:cache:laptop') > 0);

        // Tag hash: opt: + cache: + _any:tag: + tag + :entries
        $this->assertTrue($rawClient->exists('opt:cache:_any:tag:products:entries') > 0);

        // Reverse index: opt: + cache: + key + :_any:tags
        $this->assertTrue($rawClient->exists('opt:cache:laptop:_any:tags') > 0);

        $rawClient->close();
    }

    public function testOptPrefixWithTagFlush(): void
    {
        $store = $this->createStoreWithPrefixes('opt:', 'cache:');
        $tagged = new AnyTaggedCache($store, new AnyTagSet($store, ['flush-test']));

        $tagged->put('item1', 'value1', 60);
        $tagged->put('item2', 'value2', 60);

        // Verify items exist
        $this->assertSame('value1', $store->get('item1'));
        $this->assertSame('value2', $store->get('item2'));

        // Flush the tag
        $tagged->flush();

        // Items should be gone
        $this->assertNull($store->get('item1'));
        $this->assertNull($store->get('item2'));

        // Verify in Redis
        $rawClient = $this->rawRedisClientWithoutPrefix();
        $this->assertFalse($rawClient->exists('opt:cache:item1') > 0);
        $this->assertFalse($rawClient->exists('opt:cache:item2') > 0);
        $rawClient->close();
    }

    protected function tearDown(): void
    {
        // Clean up any keys created by OPT_PREFIX tests
        $patterns = ['opt:*', 'app1:*', 'app2:*'];
        foreach ($patterns as $pattern) {
            $this->cleanupKeysWithPattern($pattern);
        }

        // Also clean up no-prefix keys
        $this->cleanupKeysWithPattern('test_key');
        $this->cleanupKeysWithPattern('cache:*');

        parent::tearDown();
    }
}
