<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\JobSchedulingTest;

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
        $scheduler->job(JobWithDefaultQueue::class)->name('')->everyMinute();
        $scheduler->job(JobWithDefaultQueueTwo::class, 'another-queue')->name('')->everyMinute();
        $scheduler->job(JobWithoutDefaultQueue::class)->name('')->everyMinute();

        $events = $scheduler->events();
        foreach ($events as $event) {
            $event->run($this->app);
        }

        Queue::assertPushedOn('test-queue', JobWithDefaultQueue::class);
        Queue::assertPushedOn('another-queue', JobWithDefaultQueueTwo::class);
        Queue::assertPushedOn(null, JobWithoutDefaultQueue::class);
        $this->assertTrue(Queue::pushed(JobWithDefaultQueueTwo::class, function ($job, $pushedQueue) {
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
        $scheduler->job(JobWithDefaultConnection::class)->name('')->everyMinute();
        $scheduler->job(JobWithDefaultConnection::class, null, 'foo')->name('')->everyMinute();
        $scheduler->job(JobWithoutDefaultConnection::class)->name('')->everyMinute();
        $scheduler->job(JobWithoutDefaultConnection::class, null, 'bar')->name('')->everyMinute();

        $events = $scheduler->events();
        foreach ($events as $event) {
            $event->run($this->app);
        }

        $this->assertSame(1, Queue::pushed(JobWithDefaultConnection::class, function (JobWithDefaultConnection $job, $pushedQueue) {
            return $job->connection === 'test-connection';
        })->count());

        $this->assertSame(1, Queue::pushed(JobWithDefaultConnection::class, function (JobWithDefaultConnection $job, $pushedQueue) {
            return $job->connection === 'foo';
        })->count());

        $this->assertSame(0, Queue::pushed(JobWithDefaultConnection::class, function (JobWithDefaultConnection $job, $pushedQueue) {
            return $job->connection === null;
        })->count());

        $this->assertSame(1, Queue::pushed(JobWithoutDefaultConnection::class, function (JobWithoutDefaultConnection $job, $pushedQueue) {
            return $job->connection === null;
        })->count());

        $this->assertSame(1, Queue::pushed(JobWithoutDefaultConnection::class, function (JobWithoutDefaultConnection $job, $pushedQueue) {
            return $job->connection === 'bar';
        })->count());
    }

    // @TODO: Uncomment after Queue::route() is ported to QueueFake
    // public function testJobQueuingRespectsQueueRoutes(): void
    // {
    //     Queue::fake();
    //
    //     Queue::route(JobWithDefaultQueue::class, 'default-queue');
    //     Queue::route(JobWithoutDefaultQueue::class, 'fallback-queue');
    //     Queue::route(JobWithoutDefaultConnection::class, 'some-queue', 'some-connection');
    //
    //     /** @var Schedule $scheduler */
    //     $scheduler = $this->app->make(Schedule::class);
    //
    //     $scheduler->job(JobWithDefaultQueue::class)->name('')->everyMinute();
    //     $scheduler->job(JobWithoutDefaultQueue::class)->name('')->everyMinute();
    //     $scheduler->job(JobWithoutDefaultConnection::class)->name('')->everyMinute();
    //
    //     $events = $scheduler->events();
    //     foreach ($events as $event) {
    //         $event->run($this->app);
    //     }
    //
    //     // Own queue takes precedence over default
    //     Queue::assertPushedOn('test-queue', JobWithDefaultQueue::class);
    //     Queue::assertPushedOn('fallback-queue', JobWithoutDefaultQueue::class);
    //     Queue::connection('some-queue')->assertPushedOn('some-queue', JobWithoutDefaultConnection::class);
    // }
}

class JobWithDefaultQueue implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct()
    {
        $this->onQueue('test-queue');
    }
}

class JobWithDefaultQueueTwo implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct()
    {
        $this->onQueue('test-queue-two');
    }
}

class JobWithoutDefaultQueue implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
}

class JobWithDefaultConnection implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct()
    {
        $this->onConnection('test-connection');
    }
}

class JobWithoutDefaultConnection implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
}
