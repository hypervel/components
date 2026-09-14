<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Cache\ArrayStore;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheEventMutexTest extends TestCase
{
    protected ?CacheEventMutex $cacheMutex = null;

    protected ?Event $event = null;

    protected ?CacheFactory $cacheFactory = null;

    protected ?Repository $cacheRepository = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFactory = m::mock(CacheFactory::class);
        $this->cacheRepository = m::mock(Repository::class);
        $this->cacheFactory->shouldReceive('store')->andReturn($this->cacheRepository)->byDefault();
        $this->cacheMutex = new CacheEventMutex($this->cacheFactory);
        $this->event = new Event($this->cacheMutex, 'command');
    }

    public function testPreventOverlap(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('add');

        $this->cacheMutex->create($this->event);
    }

    public function testCustomConnection(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheFactory->expects('store')->with('test')->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('add');
        $this->cacheMutex->useStore('test');

        $this->cacheMutex->create($this->event);
    }

    public function testPreventOverlapFails(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('add')->andReturn(false);

        $this->assertFalse($this->cacheMutex->create($this->event));
    }

    public function testOverlapsForNonRunningTask(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('has')->andReturn(false);

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }

    public function testOverlapsForRunningTask(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('has')->andReturn(true);

        $this->assertTrue($this->cacheMutex->exists($this->event));
    }

    public function testResetOverlap(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('forget');

        $this->cacheMutex->forget($this->event);
    }

    public function testPreventOverlapWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(new ArrayStore);

        $this->assertTrue($this->cacheMutex->create($this->event));
    }

    public function testPreventOverlapFailsWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->times(2)->andReturn(new ArrayStore);

        // first create the lock, so we can test that the next call fails.
        $this->cacheMutex->create($this->event);

        $this->assertFalse($this->cacheMutex->create($this->event));
    }

    public function testOverlapsForNonRunningTaskWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(new ArrayStore);

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }

    public function testOverlapsForRunningTaskWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->times(2)->andReturn(new ArrayStore);

        $this->cacheMutex->create($this->event);

        $this->assertTrue($this->cacheMutex->exists($this->event));
    }

    public function testResetOverlapWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->times(3)->andReturn(new ArrayStore);

        $this->cacheMutex->create($this->event);

        $this->cacheMutex->forget($this->event);

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }
}
