<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Events\LongWaitDetected;
use Hypervel\Horizon\Listeners\MonitorWaitTimes;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class MonitorWaitTimesTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testQueuesWithLongWaitsAreFound(): void
    {
        Event::fake();

        $calc = Double::for(WaitTimeCalculator::class)->strict();
        $calc->allows('calculate')->returns([
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

    public function testQueueIgnoresLongWaits(): void
    {
        config(['horizon.waits' => ['redis:ignore-queue' => 0]]);

        Event::fake();

        $calc = Double::for(WaitTimeCalculator::class)->strict();
        $calc->expects('calculate')->returns([
            'redis:ignore-queue' => 10,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $listener = new MonitorWaitTimes(app(MetricsRepository::class));

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesSkipsWhenLockIsNotAcquired(): void
    {
        Event::fake();

        $calc = Double::for(WaitTimeCalculator::class)->strict();
        $calc->expects('calculate')->never();
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->expects('acquireWaitTimeMonitorLock')->returns(false);
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesSkipsWhenNotDueToMonitor(): void
    {
        Event::fake();

        $calc = Double::for(WaitTimeCalculator::class)->strict();
        $calc->expects('calculate')->never();
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->expects('acquireWaitTimeMonitorLock')->never();
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);
        $listener->lastMonitored = CarbonImmutable::now(); // Too soon

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesSkipsWhenNotDueToMonitorAndExecutesAfter2Minutes(): void
    {
        config(['horizon.waits' => ['redis:default' => 60]]);

        Event::fake();

        $calc = Double::for(WaitTimeCalculator::class)->strict();
        $calc->expects('calculate')->returns([
            'redis:default' => 70,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->expects('acquireWaitTimeMonitorLock')->returns(true);
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);
        $listener->lastMonitored = CarbonImmutable::now(); // Too soon

        $listener->handle();

        Event::assertNotDispatched(LongWaitDetected::class);

        CarbonImmutable::setTestNow(now()->addMinutes(2)); // Simulate time passing

        $listener->handle();

        Event::assertDispatched(LongWaitDetected::class);
    }

    public function testMonitorWaitTimesExecutesOnceWhenCalledTwice(): void
    {
        config(['horizon.waits' => ['redis:default' => 60]]);

        Event::fake();

        $calc = Double::for(WaitTimeCalculator::class)->strict();
        $calc->expects('calculate')->returns([
            'redis:default' => 70,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->expects('acquireWaitTimeMonitorLock')->returns(true);
        $this->app->instance(MetricsRepository::class, $metrics);

        $listener = new MonitorWaitTimes($metrics);
        $listener->handle();
        // Call it again to ensure it doesn't execute twice
        $listener->handle();

        Event::assertDispatchedTimes(LongWaitDetected::class, 1);
    }
}
