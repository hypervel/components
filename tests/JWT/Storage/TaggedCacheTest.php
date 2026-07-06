<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Storage;

use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\JWT\Storage\TaggedCache;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

class TaggedCacheTest extends TestCase
{
    /**
     * @var CacheRepository|MockInterface
     */
    protected CacheRepository $cache;

    protected TaggedCache $storage;

    public function testAddTheItemToAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('put')->with('foo', 'bar', 10 * 60)->once();

        $this->storage->add('foo', 'bar', 10);
    }

    public function testAddTheItemToAnyModeTaggedStorageWithDirectKeyPrefix(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('put')->with('jwt_blacklist:foo', 'bar', 10 * 60)->once();

        $this->storage->add('foo', 'bar', 10);
    }

    public function testAddTheItemToAllModeTaggedStorageForever(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('forever')->with('foo', 'bar')->once();

        $this->storage->forever('foo', 'bar');
    }

    public function testAddTheItemToAnyModeTaggedStorageForeverWithDirectKeyPrefix(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('forever')->with('jwt_blacklist:foo', 'bar')->once();

        $this->storage->forever('foo', 'bar');
    }

    public function testGetAnItemFromAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('get')->with('foo')->once()->andReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testGetAnItemFromAnyModeStorageUsesPrefixedPlainKey(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('get')->with('jwt_blacklist:foo')->once()->andReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testRemoveTheItemFromAllModeTaggedStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('forget')->with('foo')->once()->andReturn(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveTheItemFromAnyModeStorageUsesPrefixedPlainKey(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->never();
        $this->cache->shouldReceive('forget')->with('jwt_blacklist:foo')->once()->andReturn(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveAllAllModeTaggedItemsFromStorage(): void
    {
        $this->useStoreMode(TagMode::All);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('flush')->withNoArgs()->once();

        $this->storage->flush();
    }

    public function testRemoveAllAnyModeTaggedItemsFromStorageUsesUnprefixedTagName(): void
    {
        $this->useStoreMode(TagMode::Any);
        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
        $this->cache->shouldReceive('flush')->withNoArgs()->once();

        $this->storage->flush();
    }

    public function testConstructorDoesNotReadTagModeWhenStoreDoesNotSupportTags(): void
    {
        /** @var CacheRepository|MockInterface */
        $cache = m::mock(CacheRepository::class);
        /** @var MockInterface|TaggableStore */
        $store = m::mock(TaggableStore::class);

        $store->shouldReceive('supportsTags')->once()->andReturnFalse();
        $store->shouldReceive('getTagMode')->never();
        $cache->shouldReceive('getStore')->once()->andReturn($store);

        $storage = new TaggedCache($cache);

        $this->assertInstanceOf(TaggedCache::class, $storage);
    }

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
        $this->storage = new TaggedCache($this->cache);
    }
}
