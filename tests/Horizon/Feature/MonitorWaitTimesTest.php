<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Events\LongWaitDetected;
use Hypervel\Horizon\Listeners\MonitorWaitTimes;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Support\Facades\Event;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class MonitorWaitTimesTest extends IntegrationTestCase
{
    public function testQueuesWithLongWaitsAreFound()
    {
        Event::fake();

        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->shouldReceive('calculate')->andReturn([
            'redis:test-queue' => 10,
            'redis:test-queue-2' => 80,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $listener = new MonitorWaitTimes(app(MetricsRepository::class));

        $listener->handle();

        Event::assertDispatched(LongWaitDetected::class, function ($event) {
            return $event->connection == 'redis' && $event->queue == 'test-queue-2' && $event->seconds == 80;
        });
    }

    public function testQueueIgnoresLongWaits()
    {
        config(['horizon.waits' => ['redis:ignore-queue' => 0]]);

        Event::fake();

        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->expects('calculate')->andReturn([
            'redis:ignore-queue' => 10,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $listener = new MonitorWaitTimes(app(MetricsRepository::class));

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesSkipsWhenLockIsNotAcquired()
    {
        Event::fake();

        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->expects('calculate')->never();
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('acquireWaitTimeMonitorLock')->once()->andReturnFalse();
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesSkipsWhenNotDueToMonitor()
    {
        Event::fake();

        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->expects('calculate')->never();
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('acquireWaitTimeMonitorLock')->never();
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);
        $listener->lastMonitored = CarbonImmutable::now(); // Too soon

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesSkipsWhenNotDueToMonitorAndExecutesAfter2Minutes()
    {
        config(['horizon.waits' => ['redis:default' => 60]]);

        Event::fake();

        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->expects('calculate')->once()->andReturn([
            'redis:default' => 70,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('acquireWaitTimeMonitorLock')->once()->andReturnTrue();
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);
        $listener->lastMonitored = CarbonImmutable::now(); // Too soon

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);

        CarbonImmutable::setTestNow(now()->addMinutes(2)); // Simulate time passing

        $listener->handle();

        Event::assertDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesExecutesOnceWhenCalledTwice()
    {
        config(['horizon.waits' => ['redis:default' => 60]]);

        Event::fake();

        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->expects('calculate')->once()->andReturn([
            'redis:default' => 70,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('acquireWaitTimeMonitorLock')->once()->andReturnTrue();
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);
        $listener->handle();
        // Call it again to ensure it doesn't execute twice
        $listener->handle();

        Event::assertDispatchedTimes(LongWaitDetected::class, 1);
    }
}
