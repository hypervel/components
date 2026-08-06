<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Telescope;

use Hypervel\Console\Command;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\QueryWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

#[WithConfig('telescope.watchers', [
    QueryWatcher::class => [
        'enabled' => true,
        'slow' => 0.9,
    ],
])]
class TelescopeTest extends FeatureTestCase
{
    protected int $count = 0;

    public function testRunAfterRecordingCallback()
    {
        Telescope::afterRecording(function (Telescope $telescope, IncomingEntry $entry) {
            ++$this->count;
        });

        EntryModel::count();
        EntryModel::count();

        $this->assertSame(2, $this->count);
    }

    public function testAfterRecordingCallbackCanStoreAndFlush()
    {
        Telescope::afterRecording(function (Telescope $telescope, IncomingEntry $entry) {
            if (count(Telescope::getEntriesQueue()) > 1) {
                $repository = $this->app->make(EntriesRepository::class);
                $telescope->store($repository);
            }
        });

        EntryModel::count();

        $this->assertCount(1, Telescope::getEntriesQueue());

        EntryModel::count();

        $this->assertCount(0, Telescope::getEntriesQueue());

        EntryModel::count();

        $this->assertCount(1, Telescope::getEntriesQueue());
    }

    public function testRunAfterStoreCallback()
    {
        $storedEntries = null;
        $storedBatchId = null;
        Telescope::afterStoring(function (array $entries, $batchId) use (&$storedEntries, &$storedBatchId) {
            $storedEntries = $entries;
            $storedBatchId = $batchId;

            $this->count += count($entries);
        });

        EntryModel::count();

        EntryModel::count();

        $this->assertSame(0, $this->count);

        $repository = $this->app->make(EntriesRepository::class);
        Telescope::store($repository);

        $this->assertSame(2, $this->count);
        $this->assertCount(2, $storedEntries);
        $this->assertSame(36, strlen($storedBatchId));
        $this->assertInstanceOf(IncomingEntry::class, $storedEntries[0]);
    }

    public function testDontStartRecordingWhenDispatchingJobSynchronously()
    {
        Telescope::stopRecording();

        $this->assertFalse(Telescope::isRecording());

        $this->app->make(Dispatcher::class)->dispatch(
            new MySyncJob('Awesome Laravel')
        );

        $this->assertFalse(Telescope::isRecording());
    }

    public function testFlushStateClearsShouldListenCallback()
    {
        Telescope::shouldListenUsing(fn () => false);

        $this->assertFalse(Telescope::shouldListen());

        Telescope::flushState();

        $this->assertTrue(Telescope::shouldListen());
    }

    public function testResolvedCommandStartsRecording(): void
    {
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new BeforeHandle(new RecordingStateCommand('telescope:test-command')));

        $this->assertTrue(Telescope::isRecording());
    }

    #[DataProvider('ignoredCommandProvider')]
    public function testResolvedIgnoredCommandDoesNotStartRecording(string $command): void
    {
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new BeforeHandle(new RecordingStateCommand($command)));

        $this->assertFalse(Telescope::isRecording());
    }

    public static function ignoredCommandProvider(): array
    {
        return [
            ['package:discover'],
            ['watch'],
        ];
    }

    public function testResolvedConfiguredIgnoredCommandDoesNotStartRecording(): void
    {
        config()->set('telescope.ignore_commands', ['custom:ignored']);
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new BeforeHandle(new RecordingStateCommand('custom:ignored')));

        $this->assertFalse(Telescope::isRecording());
    }

    public function testSchedulerDaemonDoesNotStartRecording(): void
    {
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new BeforeHandle(new RecordingStateCommand('schedule:run')));

        $this->assertFalse(Telescope::isRecording());

        Telescope::recordCache(IncomingEntry::make(['key' => 'scheduler-cache-read']));

        $this->assertSame([], Telescope::getEntriesQueue());
    }

    public function testScheduledTaskStartsRecordingWhenSchedulerIsApproved(): void
    {
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new ScheduledTaskStarting(m::mock(Event::class)));

        $this->assertTrue(Telescope::isRecording());
    }

    public function testConfiguredIgnoredSchedulerDoesNotStartTaskRecording(): void
    {
        config()->set('telescope.ignore_commands', ['schedule:run']);
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new ScheduledTaskStarting(m::mock(Event::class)));

        $this->assertFalse(Telescope::isRecording());
    }
}

class RecordingStateCommand extends Command
{
    public function __construct(string $command)
    {
        $this->signature = $command;

        parent::__construct();
    }

    public function handle(): void
    {
    }
}

class MySyncJob implements ShouldQueue
{
    public $connection = 'sync';

    private $payload;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
    }
}
