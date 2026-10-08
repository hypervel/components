<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Storage;

use BadMethodCallException;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Jwt\Storage\CacheStorage;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

class CacheStorageTest extends TestCase
{
    /**
     * @var CacheRepository|MockInterface
     */
    protected CacheRepository $cache;

    protected CacheStorage $storage;

    public function testAddTheItemToAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->once()->andReturnFalse();

        $this->assertFalse($this->storage->add('foo', 'bar', 10));
    }

    public function testAddTheItemToAnyModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->once()->andReturnTrue();

        $this->assertTrue($this->storage->add('foo', 'bar', 10));
    }

    public function testAddTheItemToUntaggedStorage(): void
    {
        $this->useUntaggedStore();
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->once()->andReturnTrue();

        $this->assertTrue($this->storage->add('foo', 'bar', 10));
    }

    public function testAddTheItemToAllModeTaggedStorageForever(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('forever')->with($this->storageKey('foo'), 'bar')->once()->andReturnFalse();

        $this->assertFalse($this->storage->forever('foo', 'bar'));
    }

    public function testAddTheItemToAnyModeTaggedStorageForever(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('forever')->with($this->storageKey('foo'), 'bar')->once()->andReturnTrue();

        $this->assertTrue($this->storage->forever('foo', 'bar'));
    }

    public function testAddTheItemToUntaggedStorageForever(): void
    {
        $this->useUntaggedStore();
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('forever')->with($this->storageKey('foo'), 'bar')->once()->andReturnTrue();

        $this->assertTrue($this->storage->forever('foo', 'bar'));
    }

    public function testGetAnItemFromAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('get')->with($this->storageKey('foo'))->once()->andReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testGetAnItemFromAnyModeStorageUsesPlainKey(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('get')->with($this->storageKey('foo'))->once()->andReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testGetAnItemFromUntaggedStorage(): void
    {
        $this->useUntaggedStore();
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('get')->with($this->storageKey('foo'))->once()->andReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testRemoveTheItemFromAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('forget')->with($this->storageKey('foo'))->once()->andReturn(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveTheItemFromAnyModeStorageUsesPlainKey(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('forget')->with($this->storageKey('foo'))->once()->andReturn(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveTheItemFromUntaggedStorage(): void
    {
        $this->useUntaggedStore();
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('forget')->with($this->storageKey('foo'))->once()->andReturnFalse();

        $this->assertFalse($this->storage->destroy('foo'));
    }

    public function testRemoveAllAllModeTaggedItemsFromStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('flush')->withNoArgs()->once()->andReturnFalse();

        $this->assertFalse($this->storage->flush());
    }

    public function testRemoveAllAnyModeTaggedItemsFromStorage(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('flush')->withNoArgs()->once()->andReturnTrue();

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
        /** @var MockInterface|Store */
        $store = m::mock(Store::class);
        $store->shouldReceive('flush')->never();
        $storage = new CacheStorage(new Repository($store));

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs('This cache store does not support tagging.');

        $storage->flush();
    }

    public function testStoreWithTagsDisabledUsesPlainKeysWithoutReadingTagMode(): void
    {
        /** @var CacheRepository|MockInterface */
        $cache = m::mock(CacheRepository::class);
        /** @var MockInterface|TaggableStore */
        $store = m::mock(TaggableStore::class);

        $store->shouldReceive('supportsTags')->once()->andReturnFalse();
        $store->shouldReceive('getTagMode')->never();
        $cache->shouldReceive('getStore')->once()->andReturn($store);
        $cache->shouldReceive('tags')->never();
        $cache->shouldReceive('put')->with($this->storageKey('foo'), 'bar', 10 * 60)->once()->andReturnTrue();
        $cache->shouldReceive('get')->with($this->storageKey('foo'))->once()->andReturn('bar');

        $storage = new CacheStorage($cache);

        $this->assertTrue($storage->add('foo', 'bar', 10));
        $this->assertSame('bar', $storage->get('foo'));
    }

    public function testLongIdentifiersFitLimitedKeyColumns(): void
    {
        $this->useUntaggedStore();
        $this->cache->shouldReceive('forever')->with(m::capture($key), 'forever')->once()->andReturnTrue();

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
        /** @var CacheRepository|MockInterface */
        $cache = m::mock(CacheRepository::class);
        /** @var MockInterface|TaggableStore */
        $store = m::mock(TaggableStore::class);

        $store->shouldReceive('supportsTags')->once()->andReturnTrue();
        $store->shouldReceive('getTagMode')->once()->andReturn($mode);
        $cache->shouldReceive('getStore')->once()->andReturn($store);

        $this->cache = $cache;
        $this->storage = new CacheStorage($this->cache);
    }

    /**
     * Use a mocked repository whose store has no tag support.
     */
    protected function useUntaggedStore(): void
    {
        /** @var CacheRepository|MockInterface */
        $cache = m::mock(CacheRepository::class);
        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(Store::class));

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
