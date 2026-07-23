<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\UniqueJobSchedulingTest;

use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueJobPayloadContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class UniqueJobSchedulingTest extends TestCase
{
    public function testJobsPushedToQueue(): void
    {
        Queue::fake();
        $this->dispatchJobs(
            TestJob::class,
            TestJob::class,
            TestJob::class,
            TestJob::class
        );

        Queue::assertPushed(TestJob::class, 4);
    }

    public function testUniqueJobsPushedToQueue(): void
    {
        Queue::fake();
        $this->dispatchJobs(
            UniqueTestJob::class,
            UniqueTestJob::class,
            UniqueTestJob::class,
            UniqueTestJob::class
        );

        Queue::assertPushed(UniqueTestJob::class, 1);
    }

    public function testUniqueJobsRegisterMetadataForPayloadCreation(): void
    {
        Queue::fake();

        $this->dispatchJobs(UniqueTestJob::class);

        Queue::assertPushed(UniqueTestJob::class, function (UniqueTestJob $job): bool {
            $this->assertSame([
                'laravel_unique_job_cache_store' => config('cache.default'),
                'laravel_unique_job_key' => UniqueLock::getKey($job),
            ], UniqueJobPayloadContext::consume($job));

            return true;
        });
    }

    public function testUniqueLockIsReleasedWhenDispatchFailsBeforePayloadCreation(): void
    {
        $exception = new RuntimeException('Dispatch failed before payload creation.');
        $calls = 0;

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andReturnUsing(function () use (&$calls, $exception): void {
                if (++$calls === 1) {
                    throw $exception;
                }
            });

        $this->app->instance(Dispatcher::class, $dispatcher);

        $schedule = $this->scheduleUniqueJobs(2);

        try {
            $schedule->events()[0]->run($this->app);
            $this->fail('Expected the first dispatch to fail.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $schedule->events()[1]->run($this->app);
    }

    public function testUniqueLockIsRetainedWhenDispatchFailsAfterPayloadCreationBegins(): void
    {
        $exception = new RuntimeException('Dispatch failed after payload creation began.');

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function (UniqueTestJob $job) use ($exception): never {
                $this->assertNotNull(UniqueJobPayloadContext::consume($job));

                throw $exception;
            });

        $this->app->instance(Dispatcher::class, $dispatcher);

        $schedule = $this->scheduleUniqueJobs(2);

        try {
            $schedule->events()[0]->run($this->app);
            $this->fail('Expected the first dispatch to fail.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $schedule->events()[1]->run($this->app);
    }

    private function dispatchJobs(string ...$jobs): void
    {
        $scheduler = $this->app->make(Schedule::class);
        foreach ($jobs as $job) {
            $scheduler->job($job)->name('')->everyMinute();
        }
        $events = $scheduler->events();
        foreach ($events as $event) {
            $event->run($this->app);
        }
    }

    private function scheduleUniqueJobs(int $count): Schedule
    {
        $schedule = $this->app->make(Schedule::class);

        for ($index = 0; $index < $count; ++$index) {
            $schedule->job(UniqueTestJob::class)->name('')->everyMinute();
        }

        return $schedule;
    }
}

class TestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
}

class UniqueTestJob extends TestJob implements ShouldBeUnique
{
}
