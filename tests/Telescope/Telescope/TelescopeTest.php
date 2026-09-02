<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Telescope;

use Hypervel\Console\Command;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\QueryWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;

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

    public function testThrowingTagCallbackDoesNotPoisonLaterRecording(): void
    {
        Telescope::tag(fn () => throw new RuntimeException('tag failed'));

        try {
            Telescope::recordLog(IncomingEntry::make(['message' => 'first']));
            $this->fail('The tag callback exception was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('tag failed', $exception->getMessage());
        }

        Telescope::$tagUsing = [];
        Telescope::recordLog(IncomingEntry::make(['message' => 'second']));

        $this->assertSame('second', Telescope::getEntriesQueue()[0]->content['message']);
    }

    public function testThrowingFilterCallbackDoesNotPoisonLaterRecording(): void
    {
        Telescope::filter(fn () => throw new RuntimeException('filter failed'));

        try {
            Telescope::recordLog(IncomingEntry::make(['message' => 'first']));
            $this->fail('The filter callback exception was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('filter failed', $exception->getMessage());
        }

        Telescope::$filterUsing = [];
        Telescope::recordLog(IncomingEntry::make(['message' => 'second']));

        $this->assertSame('second', Telescope::getEntriesQueue()[0]->content['message']);
    }

    public function testThrowingAfterRecordingCallbackDoesNotPoisonLaterRecording(): void
    {
        Telescope::afterRecording(fn () => throw new RuntimeException('after recording failed'));

        try {
            Telescope::recordLog(IncomingEntry::make(['message' => 'first']));
            $this->fail('The after-recording callback exception was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('after recording failed', $exception->getMessage());
        }

        Telescope::$afterRecordingHook = null;
        Telescope::recordLog(IncomingEntry::make(['message' => 'second']));

        $this->assertSame('second', Telescope::getEntriesQueue()[1]->content['message']);
    }

    public function testForkedRecordingChildOwnsItsQueueAndDeferredStore(): void
    {
        $storedBatches = [];
        $store = $this->fakeRecordingStore($storedBatches);

        Telescope::recordLog(IncomingEntry::make(['message' => 'parent']));

        $coroutineId = Coroutine::fork(function (): void {
            Telescope::recordLog(IncomingEntry::make(['message' => 'child']));
        });

        Coroutine::join([$coroutineId]);
        Telescope::store($store);

        $this->assertSame(['child'], array_column($storedBatches[0], 'message'));
        $this->assertSame(['parent'], array_column($storedBatches[1], 'message'));
        $this->assertNotNull($storedBatches[0][0]['batch_id']);
        $this->assertSame($storedBatches[0][0]['batch_id'], $storedBatches[1][0]['batch_id']);
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

    public function testAllAfterStoringHooksRunAfterVoidAndFalseReturns(): void
    {
        $calls = [];

        Telescope::afterStoring(function () use (&$calls): void {
            $calls[] = 'void';
        });
        Telescope::afterStoring(function () use (&$calls): false {
            $calls[] = 'false';

            return false;
        });
        Telescope::afterStoring(function () use (&$calls): void {
            $calls[] = 'last';
        });

        EntryModel::count();
        Telescope::store($this->app->make(EntriesRepository::class));

        $this->assertSame(['void', 'false', 'last'], $calls);
    }

    public function testThrowingAfterStoringHookIsReportedAndStopsLaterHooks(): void
    {
        $failure = new RuntimeException('after storing failed');
        $reported = null;
        $laterHookRan = false;

        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $exception) use (&$reported): void {
            $reported = $exception;
        });
        Telescope::afterStoring(fn () => throw $failure);
        Telescope::afterStoring(function () use (&$laterHookRan): void {
            $laterHookRan = true;
        });

        EntryModel::count();
        Telescope::store($this->app->make(EntriesRepository::class));

        $this->assertSame($failure, $reported);
        $this->assertFalse($laterHookRan);
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
            ->dispatch(new BeforeHandle(new RecordingStateCommand('telescope:test-command'), new ArrayInput([])));

        $this->assertTrue(Telescope::isRecording());
    }

    #[DataProvider('ignoredCommandProvider')]
    public function testResolvedIgnoredCommandDoesNotStartRecording(string $command): void
    {
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new BeforeHandle(new RecordingStateCommand($command), new ArrayInput([])));

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
            ->dispatch(new BeforeHandle(new RecordingStateCommand('custom:ignored'), new ArrayInput([])));

        $this->assertFalse(Telescope::isRecording());
    }

    public function testOmittedRequestAndCommandFiltersUseEmptyLists(): void
    {
        $config = config()->array('telescope');
        unset($config['only_paths'], $config['ignore_paths'], $config['ignore_commands']);
        config()->set('telescope', $config);

        Telescope::stopRecording();
        $request = RequestContext::set(Request::create('/recordable'));
        $this->app->make(EventDispatcher::class)
            ->dispatch(new RequestReceived($request, null));

        $this->assertTrue(Telescope::isRecording());

        Telescope::stopRecording();
        $this->app->make(EventDispatcher::class)
            ->dispatch(new BeforeHandle(new RecordingStateCommand('custom:command'), new ArrayInput([])));

        $this->assertTrue(Telescope::isRecording());
    }

    public function testSchedulerDaemonDoesNotStartRecording(): void
    {
        Telescope::stopRecording();

        $this->app->make(EventDispatcher::class)
            ->dispatch(new BeforeHandle(new RecordingStateCommand('schedule:run'), new ArrayInput([])));

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
