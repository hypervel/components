<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Storage;

use Hypervel\JWT\Storage\PsrCache;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @internal
 * @coversNothing
 */
class PsrCacheTest extends TestCase
{
    /**
     * @var CacheInterface|MockInterface
     */
    protected CacheInterface $cache;

    protected PsrCache $storage;

    protected function setUp(): void
    {
        $this->cache = m::mock(CacheInterface::class);
        $this->storage = new PsrCache($this->cache);
    }

    public function testAddTheItemToStorage()
    {
        $this->cache->shouldReceive('set')->with('foo', 'bar', 10 * 60)->once();

        $this->storage->add('foo', 'bar', 10);
    }

    public function testAddTheItemToStorageForever()
    {
        $this->cache->shouldReceive('set')->with('foo', 'bar')->once();

        $this->storage->forever('foo', 'bar');
    }

    public function testGetAnItemFromStorage()
    {
        $this->cache->shouldReceive('get')->with('foo')->once()->andReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->storage->get('foo'));
    }

    public function testRemoveTheItemFromStorage()
    {
        $this->cache->shouldReceive('delete')->with('foo')->once()->andReturn(true);

        $this->assertTrue($this->storage->destroy('foo'));
    }

    public function testRemoveAllItemsFromStorage()
    {
        $this->cache->shouldReceive('clear')->withNoArgs()->once();

        $this->storage->flush();
    }
}
