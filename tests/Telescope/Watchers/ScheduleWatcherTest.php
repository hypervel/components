<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Waiter;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\IncomingEntry;
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
        $task = $this->makeTask('command');

        $this->app->make(Dispatcher::class)
            ->dispatch(new ScheduledTaskFinished($task, 0.1));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::SCHEDULED_TASK, $entry->type);
        $this->assertSame('command', $entry->content['command']);
        $this->assertSame('command description', $entry->content['description']);
        $this->assertSame('* * * * *', $entry->content['expression']);
        $this->assertSame('UTC', $entry->content['timezone']);
        $this->assertSame('user', $entry->content['user']);
        $this->assertSame('command output', $entry->content['output']);
        $this->assertSame('finished', $entry->content['status']);
        $this->assertSame(0, $entry->content['exit_code']);
        $this->assertArrayNotHasKey('exception', $entry->content);
    }

    public function testFailedScheduleRegistersOneEntry(): void
    {
        $task = $this->makeTask('failed-command', null);
        $exception = new RuntimeException('Task failed.');

        $this->app->make(Dispatcher::class)
            ->dispatch(new ScheduledTaskFailed($task, $exception));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('failed-command', $entries->first()->content['command']);
        $this->assertSame('failed', $entries->first()->content['status']);
        $this->assertNull($entries->first()->content['exit_code']);
        $this->assertSame([
            'class' => RuntimeException::class,
            'message' => 'Task failed.',
        ], $entries->first()->content['exception']);
    }

    public function testFinishedThenFailedScheduleRegistersOneEntry(): void
    {
        $task = $this->makeTask('non-zero-command', 1);
        $events = $this->app->make(Dispatcher::class);

        $events->dispatch(new ScheduledTaskFinished($task, 0.1));
        $events->dispatch(new ScheduledTaskFailed($task, new RuntimeException('Non-zero exit.')));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('failed', $entries->first()->content['status']);
        $this->assertSame(1, $entries->first()->content['exit_code']);
        $this->assertSame('Non-zero exit.', $entries->first()->content['exception']['message']);
    }

    public function testFailureAfterSuccessfulFinishedEventUpdatesTheQueuedEntry(): void
    {
        $task = $this->makeTask('listener-failure-command');
        $exception = new RuntimeException('Finished listener failed.');
        $events = $this->app->make(Dispatcher::class);
        Telescope::afterRecording(static function (Telescope $telescope, IncomingEntry $entry) use ($exception): void {
            if (($entry->content['status'] ?? null) === 'finished') {
                throw $exception;
            }
        });

        $this->assertThrows(
            fn () => $events->dispatch(new ScheduledTaskFinished($task, 0.1)),
            RuntimeException::class,
            'Finished listener failed.',
        );

        $events->dispatch(new ScheduledTaskFailed($task, $exception));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('failed', $entries->first()->content['status']);
        $this->assertSame(0, $entries->first()->content['exit_code']);
        $this->assertSame('Finished listener failed.', $entries->first()->content['exception']['message']);
    }

    public function testOverlapSkippedScheduleDoesNotRegisterAnEntry(): void
    {
        $task = $this->makeTask('overlap-command', 0, true, 0);

        $this->app->make(Dispatcher::class)
            ->dispatch(new ScheduledTaskFinished($task, 0.1));

        $this->assertCount(0, $this->loadTelescopeEntries());
    }

    public function testFilteredFinishedScheduleDoesNotSuppressTheFailedEntry(): void
    {
        Telescope::filter(static function (IncomingEntry $entry): bool {
            return ($entry->content['status'] ?? null) === 'failed';
        });

        $task = $this->makeTask('filtered-command', 0, false, 2);
        $events = $this->app->make(Dispatcher::class);

        $events->dispatch(new ScheduledTaskFinished($task, 0.1));
        $events->dispatch(new ScheduledTaskFailed($task, new RuntimeException('Later failure.')));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('failed', $entries->first()->content['status']);
        $this->assertSame('Later failure.', $entries->first()->content['exception']['message']);
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

    public function testBackgroundScheduleStoresItsEntryFromTheTaskCoroutine(): void
    {
        Telescope::stopRecording();

        $this->app->make(Schedule::class)
            ->command('list')
            ->runInBackground();

        $this->artisan('schedule:run', [
            '--once' => true,
            '--whisper' => true,
        ])->assertSuccessful();

        $query = EntryModel::query()->where('type', EntryType::SCHEDULED_TASK);
        $deadline = microtime(true) + 5.0;

        // PHPUnit's parent coroutine can resume before the background child's deferred store runs.
        while (! $query->exists()) {
            if (microtime(true) >= $deadline) {
                $this->fail('The background scheduled task did not store its Telescope entry within five seconds.');
            }

            Coroutine::sleep(0.001);
        }

        $entry = $query->sole();

        $this->assertStringContainsString('list', $entry->content['command']);
        $this->assertSame('finished', $entry->content['status']);
        $this->assertSame(0, $entry->content['exit_code']);
    }

    /**
     * Create a scheduled task with recordable metadata.
     */
    protected function makeTask(
        string $command,
        ?int $exitCode = 0,
        bool $skippedBecauseOverlapping = false,
        int $outputCalls = 1,
    ): Event {
        $task = m::mock(Event::class);
        $task->command = $command;
        $task->description = $command . ' description';
        $task->expression = '* * * * *';
        $task->timezone = 'UTC';
        $task->user = 'user';
        $task->shouldReceive('exitCode')->andReturn($exitCode);
        $task->shouldReceive('wasSkippedDueToOverlapping')->andReturn($skippedBecauseOverlapping);
        $task->shouldReceive('getOutput')
            ->times($outputCalls)
            ->with($this->app)
            ->andReturn($command . ' output');

        return $task;
    }
}
