<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StorageStore;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Fixtures\ArrayFilesystem;
use Hypervel\Tests\Console\Fixtures\FakeCacheFactory;
use Hypervel\Tests\TestCase;

class CacheSchedulingMutexTest extends TestCase
{
    protected ?CacheSchedulingMutex $cacheMutex = null;

    protected ?Event $event = null;

    protected ?CarbonImmutable $time = null;

    protected ?FakeCacheFactory $cacheFactory = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMutex = new CacheSchedulingMutex($this->cacheFactory = new FakeCacheFactory);
        $this->event = new Event(new CacheEventMutex($this->cacheFactory), 'command');
        $this->time = CarbonImmutable::now();
    }

    /**
     * Create a repository whose store does not provide locks.
     */
    protected function storageRepository(): Repository
    {
        return new Repository(new StorageStore(new ArrayFilesystem, 'cache'));
    }

    /**
     * Get the scheduling mutex name for the test time.
     */
    protected function mutexName(): string
    {
        return $this->event->mutexName() . $this->time->format('Hi');
    }

    public function testMutexReceivesCorrectCreate(): void
    {
        // A whole second keeps availableAt() from rounding the expiry up.
        CarbonImmutable::setTestNow($this->time = $this->time->startOfSecond());
        $this->cacheFactory->repository = $this->storageRepository();

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
        $this->assertTrue($this->cacheFactory->repository->has($this->mutexName()));

        CarbonImmutable::setTestNow($this->time->addSeconds(3599));
        $this->assertTrue($this->cacheFactory->repository->has($this->mutexName()));

        CarbonImmutable::setTestNow($this->time->addSeconds(3600));
        $this->assertFalse($this->cacheFactory->repository->has($this->mutexName()));
    }

    public function testCanUseCustomConnection(): void
    {
        $this->cacheFactory->repository = $this->storageRepository();
        $this->cacheMutex->useStore('test');

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
        $this->assertSame('test', $this->cacheFactory->name);
    }

    public function testPreventsMultipleRuns(): void
    {
        $repository = $this->storageRepository();
        $repository->add($this->mutexName(), true, 3600);
        $this->cacheFactory->repository = $repository;

        $this->assertFalse($this->cacheMutex->create($this->event, $this->time));
    }

    public function testChecksForNonRunSchedule(): void
    {
        $this->cacheFactory->repository = $this->storageRepository();

        $this->assertFalse($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testChecksForAlreadyRunSchedule(): void
    {
        $repository = $this->storageRepository();
        $repository->add($this->mutexName(), true, 3600);
        $this->cacheFactory->repository = $repository;

        $this->assertTrue($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testMutexReceivesCorrectCreateWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
    }

    public function testPreventsMultipleRunsWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        // first create the lock, so we can test that the next call fails.
        $this->cacheMutex->create($this->event, $this->time);

        $this->assertFalse($this->cacheMutex->create($this->event, $this->time));
    }

    public function testChecksForNonRunScheduleWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        $this->assertFalse($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testChecksForAlreadyRunScheduleWithLockProvider(): void
    {
        $this->cacheFactory->repository = new Repository(new ArrayStore);

        $this->cacheMutex->create($this->event, $this->time);

        $this->assertTrue($this->cacheMutex->exists($this->event, $this->time));
    }
}
