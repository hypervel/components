<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Cache\Contracts\Repository;
use Hypervel\Cache\Contracts\Store;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\Event;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CacheEventMutexTest extends TestCase
{
    protected ?CacheEventMutex $cacheMutex = null;

    protected ?Event $event = null;

    protected ?CacheFactory $cacheFactory = null;

    protected ?Repository $cacheRepository = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFactory = m::mock(CacheFactory::class);
        $this->cacheRepository = m::mock(Repository::class);
        $this->cacheFactory->shouldReceive('store')->andReturn($this->cacheRepository);
        $this->cacheMutex = new CacheEventMutex($this->cacheFactory);
        $this->event = new Event($this->cacheMutex, 'command');
    }

    public function testPreventOverlap()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('add')->once();

        $this->cacheMutex->create($this->event);
    }

    public function testCustomConnection()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheFactory->shouldReceive('store')->with('test')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('add')->once();
        $this->cacheMutex->useStore('test');

        $this->cacheMutex->create($this->event);
    }

    public function testPreventOverlapFails()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('add')->once()->andReturn(false);

        $this->assertFalse($this->cacheMutex->create($this->event));
    }

    public function testOverlapsForNonRunningTask()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('has')->once()->andReturn(false);

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }

    public function testOverlapsForRunningTask()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('has')->once()->andReturn(true);

        $this->assertTrue($this->cacheMutex->exists($this->event));
    }

    public function testResetOverlap()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('forget')->once();

        $this->cacheMutex->forget($this->event);
    }

    public function testPreventOverlapWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->assertTrue($this->cacheMutex->create($this->event));
    }

    public function testPreventOverlapFailsWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        // first create the lock, so we can test that the next call fails.
        $this->cacheMutex->create($this->event);

        $this->assertFalse($this->cacheMutex->create($this->event));
    }

    public function testOverlapsForNonRunningTaskWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }

    public function testOverlapsForRunningTaskWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->cacheMutex->create($this->event);

        $this->assertTrue($this->cacheMutex->exists($this->event));
    }

    public function testResetOverlapWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->cacheMutex->create($this->event);

        $this->cacheMutex->forget($this->event);

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }
}
