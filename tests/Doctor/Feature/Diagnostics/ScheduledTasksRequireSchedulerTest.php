<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Doctor\Diagnostics\ScheduledTasksRequireScheduler;
use Hypervel\Doctor\Results\Link;
use Hypervel\Tests\Doctor\TestCase;

class ScheduledTasksRequireSchedulerTest extends TestCase
{
    public function testPassesWhenNoScheduledTasksAreRegistered(): void
    {
        $this->app->instance(Schedule::class, new Schedule);

        $result = (new ScheduledTasksRequireScheduler)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('The application does not have scheduled tasks.', $result->summary);
    }

    public function testNoticesWhenScheduledTasksAreRegistered(): void
    {
        $schedule = new Schedule;
        $schedule->call(fn (): bool => true)->everyMinute();

        $this->app->instance(Schedule::class, $schedule);

        $result = (new ScheduledTasksRequireScheduler)->check();

        $this->assertSame('notice', $result->status->value);
        $this->assertSame('The application has scheduled tasks.', $result->summary);
        $this->assertSame('Keep `php artisan schedule:run` running; supervise this long-running process in production.', $result->remediation);
        $this->assertEquals([Link::docs('scheduling', 'running-the-scheduler')], $result->links);
    }
}
