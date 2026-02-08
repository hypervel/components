<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ScheduleWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class ScheduleWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(Repository::class)
            ->set('telescope.watchers', [
                ScheduleWatcher::class => true,
            ]);

        $_SERVER['argv'][1] = 'schedule:run';

        $this->startTelescope();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['argv'][1]);

        parent::tearDown();
    }

    public function testScheduleRegistersEntry()
    {
        $this->app->get(EventDispatcherInterface::class)
            ->dispatch(new ScheduledTaskStarting(
                m::mock(Event::class)
            ));

        $task = m::mock(Event::class);
        $task->command = $command = 'command';
        $task->description = $description = 'description';
        $task->expression = $expression = '* * * * *';
        $task->timezone = $timezone = 'UTC';
        $task->user = $user = 'user';
        $task->shouldReceive('getOutput')
            ->once()
            ->andReturn($output = 'success');

        $this->app->get(EventDispatcherInterface::class)
            ->dispatch(new ScheduledTaskFinished($task, 0.1));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::SCHEDULED_TASK, $entry->type);
        $this->assertSame($command, $entry->content['command']);
        $this->assertSame($description, $entry->content['description']);
        $this->assertSame($expression, $entry->content['expression']);
        $this->assertSame($timezone, $entry->content['timezone']);
        $this->assertSame($user, $entry->content['user']);
        $this->assertSame($output, $entry->content['output']);
    }
}
