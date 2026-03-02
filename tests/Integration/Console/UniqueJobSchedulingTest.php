<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\UniqueJobSchedulingTest;

use Hypervel\Bus\Queueable;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
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

    private function dispatchJobs(string ...$jobs): void
    {
        /** @var Schedule $scheduler */
        $scheduler = $this->app->make(Schedule::class);
        foreach ($jobs as $job) {
            $scheduler->job($job)->name('')->everyMinute();
        }
        $events = $scheduler->events();
        foreach ($events as $event) {
            $event->run($this->app);
        }
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
