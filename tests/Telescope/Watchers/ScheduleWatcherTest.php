<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Waiter;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\ScheduleWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use RuntimeException;

#[WithConfig('telescope.watchers', [
    ScheduleWatcher::class => true,
])]
class ScheduleWatcherTest extends FeatureTestCase
{
    public function testScheduleRegistersEntryWithoutACommandStartEvent(): void
    {
        $task = m::mock(Event::class);
        $task->command = $command = 'command';
        $task->description = $description = 'description';
        $task->expression = $expression = '* * * * *';
        $task->timezone = $timezone = 'UTC';
        $task->user = $user = 'user';
        $task->shouldReceive('getOutput')
            ->once()
            ->andReturn($output = 'success');

        $this->app->make(Dispatcher::class)
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

    public function testFailedScheduleRegistersOneEntry(): void
    {
        $task = $this->makeTask('failed-command');

        $this->app->make(Dispatcher::class)
            ->dispatch(new ScheduledTaskFailed($task, new RuntimeException('Task failed.')));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('failed-command', $entries->first()->content['command']);
    }

    public function testFinishedThenFailedScheduleRegistersOneEntry(): void
    {
        $task = $this->makeTask('non-zero-command');
        $events = $this->app->make(Dispatcher::class);

        $events->dispatch(new ScheduledTaskFinished($task, 0.1));
        $events->dispatch(new ScheduledTaskFailed($task, new RuntimeException('Non-zero exit.')));

        $this->assertCount(1, $this->loadTelescopeEntries());
    }

    public function testDifferentSchedulesRegisterSeparateEntriesInTheSameCoroutine(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new ScheduledTaskFinished($this->makeTask('first-command'), 0.1));
        $events->dispatch(new ScheduledTaskFinished($this->makeTask('second-command'), 0.1));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing(
            ['first-command', 'second-command'],
            $entries->pluck('content.command')->all(),
        );
    }

    public function testIgnoredSchedulerDoesNotRegisterAnEntry(): void
    {
        config()->set('telescope.ignore_commands', ['schedule:run']);
        Telescope::stopRecording();

        $task = m::mock(Event::class);
        $task->shouldNotReceive('getOutput');
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new ScheduledTaskStarting($task));
        $events->dispatch(new ScheduledTaskFinished($task, 0.1));

        $this->assertCount(0, $this->loadTelescopeEntries());
    }

    public function testFiniteTaskCoroutinesStoreDistinctBatchesBeforeTheirParentExits(): void
    {
        config()->set('telescope.defer', true);
        Telescope::stopRecording();
        CoroutineContext::forget(Telescope::BATCH_ID_CONTEXT_KEY);

        $events = $this->app->make(Dispatcher::class);

        foreach (['first-command', 'second-command'] as $command) {
            $task = $this->makeTask($command);

            (new Waiter(-1))->wait(function () use ($events, $task): void {
                $events->dispatch(new ScheduledTaskStarting($task));
                $events->dispatch(new ScheduledTaskFinished($task, 0.1));
            });
        }

        $entries = EntryModel::query()->get();

        $this->assertCount(2, $entries);
        $this->assertCount(2, $entries->pluck('batch_id')->unique());
    }

    /**
     * Create a scheduled task with recordable metadata.
     */
    protected function makeTask(string $command): Event
    {
        $task = m::mock(Event::class);
        $task->command = $command;
        $task->description = $command . ' description';
        $task->expression = '* * * * *';
        $task->timezone = 'UTC';
        $task->user = 'user';
        $task->shouldReceive('getOutput')
            ->once()
            ->with($this->app)
            ->andReturn($command . ' output');

        return $task;
    }
}
