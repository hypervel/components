<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Cache\ArrayStore;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheSchedulingMutexTest extends TestCase
{
    protected ?CacheSchedulingMutex $cacheMutex = null;

    protected ?Event $event = null;

    protected ?CarbonImmutable $time = null;

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
        $this->cacheMutex = new CacheSchedulingMutex($this->cacheFactory);
        $this->event = new Event(new CacheEventMutex($this->cacheFactory), 'command');
        $this->time = CarbonImmutable::now();
    }

    public function testMutexReceivesCorrectCreate(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('add')->with($this->event->mutexName() . $this->time->format('Hi'), true, 3600)->andReturn(true);

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
    }

    public function testCanUseCustomConnection(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheFactory->expects('store')->with('test')->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('add')->with($this->event->mutexName() . $this->time->format('Hi'), true, 3600)->andReturn(true);
        $this->cacheMutex->useStore('test');

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
    }

    public function testPreventsMultipleRuns(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('add')->with($this->event->mutexName() . $this->time->format('Hi'), true, 3600)->andReturn(false);

        $this->assertFalse($this->cacheMutex->create($this->event, $this->time));
    }

    public function testChecksForNonRunSchedule(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('has')->with($this->event->mutexName() . $this->time->format('Hi'))->andReturn(false);

        $this->assertFalse($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testChecksForAlreadyRunSchedule(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->expects('has')->with($this->event->mutexName() . $this->time->format('Hi'))->andReturn(true);

        $this->assertTrue($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testMutexReceivesCorrectCreateWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(new ArrayStore);

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
    }

    public function testPreventsMultipleRunsWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->times(2)->andReturn(new ArrayStore);

        // first create the lock, so we can test that the next call fails.
        $this->cacheMutex->create($this->event, $this->time);

        $this->assertFalse($this->cacheMutex->create($this->event, $this->time));
    }

    public function testChecksForNonRunScheduleWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->andReturn(new ArrayStore);

        $this->assertFalse($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testChecksForAlreadyRunScheduleWithLockProvider(): void
    {
        $this->cacheRepository->expects('getStore')->times(2)->andReturn(new ArrayStore);

        $this->cacheMutex->create($this->event, $this->time);

        $this->assertTrue($this->cacheMutex->exists($this->event, $this->time));
    }
}
