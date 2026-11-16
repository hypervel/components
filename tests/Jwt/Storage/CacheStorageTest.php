<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Storage;

use BadMethodCallException;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TaggedCache;
use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Jwt\Storage\CacheStorage;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class CacheStorageTest extends TestCase
{
    use VerifiesDoubles;

    protected Repository&DoubleInterface $cache;

    protected CacheStorage $storage;

    public function testAddTheItemToAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->returns(false);

        $this->assertFalse($this->storage->add('foo', 'bar', 10));
    }

    public function testAddTheItemToAnyModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->returns(true);

        $this->assertTrue($this->storage->add('foo', 'bar', 10));
    }

    public function testAddTheItemToUntaggedStorage(): void
    {
        $this->useUntaggedStore();
        $this->cache->expects('tags')->never();
        $this->cache->expects('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->returns(true);

        $this->assertTrue($this->storage->add('foo', 'bar', 10));
    }

    public function testAddTheItemToAllModeTaggedStorageForever(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('forever')->with($this->storageKey('foo'), 'bar')->returns(false);

        $this->assertFalse($this->storage->forever('foo', 'bar'));
    }

    public function testAddTheItemToAnyModeTaggedStorageForever(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('forever')->with($this->storageKey('foo'), 'bar')->returns(true);

        $this->assertTrue($this->storage->forever('foo', 'bar'));
    }

    public function testAddTheItemToUntaggedStorageForever(): void
    {
        $this->useUntaggedStore();
        $this->cache->expects('tags')->never();
        $this->cache->expects('forever')->with($this->storageKey('foo'), 'bar')->returns(true);

        $this->assertTrue($this->storage->forever('foo', 'bar'));
    }

    public function testGetAnItemFromAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('get')->with($this->storageKey('foo'))->returns(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testGetAnItemFromAnyModeStorageUsesPlainKey(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->expects('tags')->never();
        $this->cache->expects('get')->with($this->storageKey('foo'))->returns(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testGetAnItemFromUntaggedStorage(): void
    {
        $this->useUntaggedStore();
        $this->cache->expects('tags')->never();
        $this->cache->expects('get')->with($this->storageKey('foo'))->returns(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testRemoveTheItemFromAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('forget')->with($this->storageKey('foo'))->returns(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveTheItemFromAnyModeStorageUsesPlainKey(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->expects('tags')->never();
        $this->cache->expects('forget')->with($this->storageKey('foo'))->returns(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveTheItemFromUntaggedStorage(): void
    {
        $this->useUntaggedStore();
        $this->cache->expects('tags')->never();
        $this->cache->expects('forget')->with($this->storageKey('foo'))->returns(false);

        $this->assertFalse($this->storage->destroy('foo'));
    }

    public function testRemoveAllAllModeTaggedItemsFromStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('flush')->with()->returns(false);

        $this->assertFalse($this->storage->flush());
    }

    public function testRemoveAllAnyModeTaggedItemsFromStorage(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->expects('tags')->with(['jwt_blacklist'])->returns($this->cache);
        $this->cache->expects('flush')->with()->returns(true);

        $this->assertTrue($this->storage->flush());
    }

    public function testRemoveAllItemsKeepsUnrelatedCacheEntries(): void
    {
        $cache = new Repository(new ArrayStore);
        $storage = new CacheStorage($cache);

        $cache->forever('foo', 'unrelated');
        $storage->forever('foo', 'forever');

        $this->assertTrue($storage->flush());
        $this->assertNull($storage->get('foo'));
        $this->assertSame('unrelated', $cache->get('foo'));
    }

    public function testRemoveAllItemsFromUntaggedStorageThrowsWithoutFlushingTheStore(): void
    {
        $store = Double::for(Store::class)->strict();
        $store->expects('flush')->never();
        $storage = new CacheStorage(new Repository($store));

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs('This cache store does not support tagging.');

        $storage->flush();
    }

    public function testStoreWithTagsDisabledUsesPlainKeysWithoutReadingTagMode(): void
    {
        $cache = Double::for(Repository::class)->strict();
        $store = Double::for(TaggableStore::class)->strict();

        $store->expects('supportsTags')->returns(false);
        $store->expects('getTagMode')->never();
        $cache->expects('getStore')->returns($store);
        $cache->expects('tags')->never();
        $cache->expects('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->returns(true);
        $cache->expects('get')->with($this->storageKey('foo'))->returns('bar');

        $storage = new CacheStorage($cache);

        $this->assertTrue($storage->add('foo', 'bar', 10));
        $this->assertSame('bar', $storage->get('foo'));
    }

    public function testLongIdentifiersFitLimitedKeyColumns(): void
    {
        $this->useUntaggedStore();
        $this->cache->expects('forever')->with(Argument::capture($key), 'forever')->returns(true);

        $this->assertTrue($this->storage->forever(str_repeat('a', 1000), 'forever'));
        $this->assertLessThanOrEqual(255, strlen($key));
    }

    public function testIdentifiersSharingALongPrefixStayDistinct(): void
    {
        $storage = new CacheStorage(new Repository(new ArrayStore));
        $prefix = str_repeat('a', 1000);

        $storage->forever($prefix . '1', 'forever');

        $this->assertSame('forever', $storage->get($prefix . '1'));
        $this->assertNull($storage->get($prefix . '2'));
    }

    /**
     * Use a mocked repository whose store supports tags in the given mode.
     */
    protected function useStoreMode(TagMode $mode): void
    {
        $cache = Double::for(TaggedCache::class)->strict();
        $store = Double::for(TaggableStore::class)->strict();

        $store->expects('supportsTags')->returns(true);
        $store->expects('getTagMode')->returns($mode);
        $cache->expects('getStore')->returns($store);

        $this->cache = $cache;
        $this->storage = new CacheStorage($this->cache);
    }

    /**
     * Use a mocked repository whose store has no tag support.
     */
    protected function useUntaggedStore(): void
    {
        $cache = Double::for(Repository::class)->strict();
        $cache->expects('getStore')->returns(Double::for(Store::class)->strict());

        $this->cache = $cache;
        $this->storage = new CacheStorage($this->cache);
    }

    /**
     * Get the cache key that stores the given blacklist key.
     */
    protected function storageKey(string $key): string
    {
        return 'jwt_blacklist:' . hash('sha256', $key);
    }
}
