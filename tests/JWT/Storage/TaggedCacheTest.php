<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Storage;

use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\JWT\Storage\TaggedCache;
use Hypervel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class TaggedCacheTest extends TestCase
{
    /**
     * @var CacheRepository|MockInterface
     */
    protected CacheRepository $cache;

    protected TaggedCache $storage;

    protected function setUp(): void
    {
        /** @var CacheRepository|MockInterface */
        $cache = Mockery::mock(CacheRepository::class);

        $this->cache = $cache;
        $this->storage = new TaggedCache($this->cache);

        $this->cache->shouldReceive('tags')->with(['jwt_blacklist'])->once()->andReturnSelf();
    }

    public function testAddTheItemToTaggedStorage()
    {
        $this->cache->shouldReceive('put')->with('foo', 'bar', 10 * 60)->once();

        $this->storage->add('foo', 'bar', 10);
    }

    public function testAddTheItemToTaggedStorageForever()
    {
        $this->cache->shouldReceive('forever')->with('foo', 'bar')->once();

        $this->storage->forever('foo', 'bar');
    }

    public function testGetAnItemFromTaggedStorage()
    {
        $this->cache->shouldReceive('get')->with('foo')->once()->andReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testRemoveTheItemFromTaggedStorage()
    {
        $this->cache->shouldReceive('forget')->with('foo')->once()->andReturn(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveAllTaggedItemsFromStorage()
    {
        $this->cache->shouldReceive('flush')->withNoArgs()->once();

        $this->storage->flush();
    }
}
