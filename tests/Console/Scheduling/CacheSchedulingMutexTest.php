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
use Hypervel\Support\Carbon;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CacheSchedulingMutexTest extends TestCase
{
    protected ?CacheSchedulingMutex $cacheMutex = null;

    protected ?Event $event = null;

    protected ?Carbon $time = null;

    protected ?CacheFactory $cacheFactory = null;

    protected ?Repository $cacheRepository = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFactory = m::mock(CacheFactory::class);
        $this->cacheRepository = m::mock(Repository::class);
        $this->cacheFactory->shouldReceive('store')->andReturn($this->cacheRepository);
        $this->cacheMutex = new CacheSchedulingMutex($this->cacheFactory);
        $this->event = new Event(new CacheEventMutex($this->cacheFactory), 'command');
        $this->time = Carbon::now();
    }

    public function testMutexReceivesCorrectCreate()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('add')->once()->with($this->event->mutexName() . $this->time->format('Hi'), true, 3600)->andReturn(true);

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
    }

    public function testCanUseCustomConnection()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheFactory->shouldReceive('store')->with('test')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('add')->once()->with($this->event->mutexName() . $this->time->format('Hi'), true, 3600)->andReturn(true);
        $this->cacheMutex->useStore('test');

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
    }

    public function testPreventsMultipleRuns()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('add')->once()->with($this->event->mutexName() . $this->time->format('Hi'), true, 3600)->andReturn(false);

        $this->assertFalse($this->cacheMutex->create($this->event, $this->time));
    }

    public function testChecksForNonRunSchedule()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('has')->once()->with($this->event->mutexName() . $this->time->format('Hi'))->andReturn(false);

        $this->assertFalse($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testChecksForAlreadyRunSchedule()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(m::mock(Store::class));
        $this->cacheRepository->shouldReceive('has')->with($this->event->mutexName() . $this->time->format('Hi'))->andReturn(true);

        $this->assertTrue($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testMutexReceivesCorrectCreateWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->assertTrue($this->cacheMutex->create($this->event, $this->time));
    }

    public function testPreventsMultipleRunsWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->cacheMutex->create($this->event, $this->time);

        $this->assertFalse($this->cacheMutex->create($this->event, $this->time));
    }

    public function testChecksForNonRunScheduleWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->assertFalse($this->cacheMutex->exists($this->event, $this->time));
    }

    public function testChecksForAlreadyRunScheduleWithLockProvider()
    {
        $this->cacheRepository->shouldReceive('getStore')->andReturn(new ArrayStore());

        $this->cacheMutex->create($this->event, $this->time);

        $this->assertTrue($this->cacheMutex->exists($this->event, $this->time));
    }
}
