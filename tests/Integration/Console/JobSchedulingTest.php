<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Bus\Queueable;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class JobSchedulingTest extends TestCase
{
    public function testJobQueuingRespectsJobQueue(): void
    {
        Queue::fake();

        /** @var Schedule $scheduler */
        $scheduler = $this->app->make(Schedule::class);

        // all job names were set to an empty string so that the registered shutdown function in CallbackEvent does nothing
        // that function would in this test environment fire after everything was run, including the tearDown method
        // (which flushes the entire container) which would then result in a ReflectionException when the container would try
        // to resolve the config service (which is needed in order to resolve the cache store for the mutex that is being cleared)
        $scheduler->job(JobSchedulingJobWithDefaultQueue::class)->name('')->everyMinute();
        $scheduler->job(JobSchedulingJobWithDefaultQueueTwo::class, 'another-queue')->name('')->everyMinute();
        $scheduler->job(JobSchedulingJobWithoutDefaultQueue::class)->name('')->everyMinute();

        $events = $scheduler->events();
        foreach ($events as $event) {
            $event->run($this->app);
        }

        Queue::assertPushedOn('test-queue', JobSchedulingJobWithDefaultQueue::class);
        Queue::assertPushedOn('another-queue', JobSchedulingJobWithDefaultQueueTwo::class);
        Queue::assertPushedOn(null, JobSchedulingJobWithoutDefaultQueue::class);
        $this->assertTrue(Queue::pushed(JobSchedulingJobWithDefaultQueueTwo::class, function ($job, $pushedQueue) {
            return $pushedQueue === 'test-queue-two';
        })->isEmpty());
    }

    public function testJobQueuingRespectsJobConnection(): void
    {
        Queue::fake();

        /** @var Schedule $scheduler */
        $scheduler = $this->app->make(Schedule::class);

        // all job names were set to an empty string so that the registered shutdown function in CallbackEvent does nothing
        // that function would in this test environment fire after everything was run, including the tearDown method
        // (which flushes the entire container) which would then result in a ReflectionException when the container would try
        // to resolve the config service (which is needed in order to resolve the cache store for the mutex that is being cleared)
        $scheduler->job(JobSchedulingJobWithDefaultConnection::class)->name('')->everyMinute();
        $scheduler->job(JobSchedulingJobWithDefaultConnection::class, null, 'foo')->name('')->everyMinute();
        $scheduler->job(JobSchedulingJobWithoutDefaultConnection::class)->name('')->everyMinute();
        $scheduler->job(JobSchedulingJobWithoutDefaultConnection::class, null, 'bar')->name('')->everyMinute();

        $events = $scheduler->events();
        foreach ($events as $event) {
            $event->run($this->app);
        }

        $this->assertSame(1, Queue::pushed(JobSchedulingJobWithDefaultConnection::class, function (JobSchedulingJobWithDefaultConnection $job, $pushedQueue) {
            return $job->connection === 'test-connection';
        })->count());

        $this->assertSame(1, Queue::pushed(JobSchedulingJobWithDefaultConnection::class, function (JobSchedulingJobWithDefaultConnection $job, $pushedQueue) {
            return $job->connection === 'foo';
        })->count());

        $this->assertSame(0, Queue::pushed(JobSchedulingJobWithDefaultConnection::class, function (JobSchedulingJobWithDefaultConnection $job, $pushedQueue) {
            return $job->connection === null;
        })->count());

        $this->assertSame(1, Queue::pushed(JobSchedulingJobWithoutDefaultConnection::class, function (JobSchedulingJobWithoutDefaultConnection $job, $pushedQueue) {
            return $job->connection === null;
        })->count());

        $this->assertSame(1, Queue::pushed(JobSchedulingJobWithoutDefaultConnection::class, function (JobSchedulingJobWithoutDefaultConnection $job, $pushedQueue) {
            return $job->connection === 'bar';
        })->count());
    }

    // @TODO: Uncomment after Queue::route() is ported to QueueFake
    // public function testJobQueuingRespectsQueueRoutes(): void
    // {
    //     Queue::fake();
    //
    //     Queue::route(JobSchedulingJobWithDefaultQueue::class, 'default-queue');
    //     Queue::route(JobSchedulingJobWithoutDefaultQueue::class, 'fallback-queue');
    //     Queue::route(JobSchedulingJobWithoutDefaultConnection::class, 'some-queue', 'some-connection');
    //
    //     /** @var Schedule $scheduler */
    //     $scheduler = $this->app->make(Schedule::class);
    //
    //     $scheduler->job(JobSchedulingJobWithDefaultQueue::class)->name('')->everyMinute();
    //     $scheduler->job(JobSchedulingJobWithoutDefaultQueue::class)->name('')->everyMinute();
    //     $scheduler->job(JobSchedulingJobWithoutDefaultConnection::class)->name('')->everyMinute();
    //
    //     $events = $scheduler->events();
    //     foreach ($events as $event) {
    //         $event->run($this->app);
    //     }
    //
    //     // Own queue takes precedence over default
    //     Queue::assertPushedOn('test-queue', JobSchedulingJobWithDefaultQueue::class);
    //     Queue::assertPushedOn('fallback-queue', JobSchedulingJobWithoutDefaultQueue::class);
    //     Queue::connection('some-queue')->assertPushedOn('some-queue', JobSchedulingJobWithoutDefaultConnection::class);
    // }
}

class JobSchedulingJobWithDefaultQueue implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct()
    {
        $this->onQueue('test-queue');
    }
}

class JobSchedulingJobWithDefaultQueueTwo implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct()
    {
        $this->onQueue('test-queue-two');
    }
}

class JobSchedulingJobWithoutDefaultQueue implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
}

class JobSchedulingJobWithDefaultConnection implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct()
    {
        $this->onConnection('test-connection');
    }
}

class JobSchedulingJobWithoutDefaultConnection implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
}
