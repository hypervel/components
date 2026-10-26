<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StorageStore;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Tests\Cache\Fixtures\ArrayFilesystem;
use Hypervel\Tests\Console\Fixtures\FakeCacheFactory;
use Hypervel\Tests\TestCase;

class CacheEventMutexTest extends TestCase
{
    protected ?CacheEventMutex $cacheMutex = null;

    protected ?Event $event = null;

    protected ?FakeCacheFactory $cacheFactory = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMutex = new CacheEventMutex($this->cacheFactory = new FakeCacheFactory);
        $this->event = new Event($this->cacheMutex, 'command');
    }

    /**
     * Create a repository whose store does not provide locks.
     */
    protected function storageRepository(): Repository
    {
        return new Repository(new StorageStore(new ArrayFilesystem, 'cache'));
    }

    public function testPreventOverlap(): void
    {
        $this->cacheFactory->repository = $this->storageRepository();

        $this->assertTrue($this->cacheMutex->create($this->event));
    }

    public function testCustomConnection(): void
    {
        $this->cacheFactory->repository = $this->storageRepository();
        $this->cacheMutex->useStore('test');

        $this->assertTrue($this->cacheMutex->create($this->event));
        $this->assertSame('test', $this->cacheFactory->name);
    }

    public function testPreventOverlapFails(): void
    {
        $repository = $this->storageRepository();
        $repository->add($this->event->mutexName(), true, 60);
        $this->cacheFactory->repository = $repository;

        $this->assertFalse($this->cacheMutex->create($this->event));
    }

    public function testOverlapsForNonRunningTask(): void
    {
        $this->cacheFactory->repository = $this->storageRepository();

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }

    public function testOverlapsForRunningTask(): void
    {
        $repository = $this->storageRepository();
        $repository->add($this->event->mutexName(), true, 60);
        $this->cacheFactory->repository = $repository;

        $this->assertTrue($this->cacheMutex->exists($this->event));
    }

    public function testResetOverlap(): void
    {
        $repository = $this->storageRepository();
        $repository->add($this->event->mutexName(), true, 60);
        $this->cacheFactory->repository = $repository;

        $this->cacheMutex->forget($this->event);

        $this->assertFalse($repository->has($this->event->mutexName()));
    }

    public function testPreventOverlapWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        $this->assertTrue($this->cacheMutex->create($this->event));
    }

    public function testPreventOverlapFailsWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        // first create the lock, so we can test that the next call fails.
        $this->cacheMutex->create($this->event);

        $this->assertFalse($this->cacheMutex->create($this->event));
    }

    public function testOverlapsForNonRunningTaskWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }

    public function testOverlapsForRunningTaskWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        $this->cacheMutex->create($this->event);

        $this->assertTrue($this->cacheMutex->exists($this->event));
    }

    public function testResetOverlapWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        $this->cacheMutex->create($this->event);

        $this->cacheMutex->forget($this->event);

        $this->assertFalse($this->cacheMutex->exists($this->event));
    }
}
