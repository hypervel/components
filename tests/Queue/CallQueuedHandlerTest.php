<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\Dispatcher as ConcreteBusDispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcher;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Swoole\Coroutine\Channel;

use function Hypervel\Coroutine\parallel;

class CallQueuedHandlerTest extends TestCase
{
    use VerifiesDoubles;

    protected Container $container;

    protected Repository $cache;

    protected UniqueLock $locks;

    protected CallQueuedHandler $handler;

    /**
     * Set up the handler with an array-backed lock cache.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        $this->cache = new Repository(new ArrayStore);
        $this->container->instance(Cache::class, $this->cache);
        $this->locks = new UniqueLock($this->cache);
        $this->handler = new CallQueuedHandler(new ConcreteBusDispatcher($this->container), $this->container);
    }

    public function testUniqueUntilProcessingLockIsReleasedOnTerminalFailure(): void
    {
        $command = new UniqueUntilProcessingFailureJob;
        $this->assertTrue($this->locks->acquire($command));

        $this->handler->failed(['command' => serialize($command)], new RuntimeException, 'job-id', new FakeJob);

        $this->assertTrue($this->locks->acquire(new UniqueUntilProcessingFailureJob));
    }

    public function testUniqueUntilProcessingListenerLockIsReleasedOnTerminalFailure(): void
    {
        $command = new CallQueuedListener(UniqueUntilProcessingFailureJob::class, 'handle', []);
        $command->shouldBeUnique = true;
        $command->shouldBeUniqueUntilProcessing = true;
        $this->assertTrue($this->locks->acquire($command));

        $this->handler->failed(['command' => serialize($command)], new RuntimeException, 'job-id', new FakeJob);

        $this->assertTrue($this->locks->acquire(clone $command));
    }

    public function testFailureDoesNotReleaseReplacementJobLock(): void
    {
        $command = new UniqueUntilProcessingFailureJob;
        $this->assertTrue($this->locks->acquire($command));
        $this->locks->release($command);
        $this->assertTrue($this->locks->acquire(new UniqueUntilProcessingFailureJob));

        $this->handler->failed(['command' => serialize($command)], new RuntimeException, 'job-id', new FakeJob);

        $this->assertFalse($this->locks->acquire(new UniqueUntilProcessingFailureJob));
    }

    public function testLegacyUniqueUntilProcessingFailureDoesNotForceReleaseAnUnownedLock(): void
    {
        $command = new UniqueUntilProcessingFailureJob;
        $this->assertTrue($this->locks->acquire($command));
        $command->uniqueLockOwner = '';

        $this->handler->failed(['command' => serialize($command)], new RuntimeException, 'job-id', new FakeJob);

        $this->assertFalse($this->locks->acquire(new UniqueUntilProcessingFailureJob));
    }

    public function testLegacyUniqueJobLockIsStillReleasedOnTerminalFailure(): void
    {
        $command = new LegacyUniqueFailureJob;
        $this->assertTrue($this->locks->acquire($command));

        $this->handler->failed(['command' => serialize($command)], new RuntimeException, 'job-id', new FakeJob);

        $this->assertTrue($this->locks->acquire(new LegacyUniqueFailureJob));
    }

    public function testUniqueLockReleaseOverrideCanForwardOwnershipRequirement(): void
    {
        $locks = new CustomUniqueLock($this->cache);
        $command = new UniqueUntilProcessingFailureJob;
        $this->assertTrue($locks->acquire($command));

        $locks->release($command, onlyOwned: true);

        $this->assertTrue($locks->onlyOwned);
        $this->assertTrue($locks->acquire(new UniqueUntilProcessingFailureJob));
    }

    public function testHandlerLockReleaseOverrideReceivesOwnershipRequirement(): void
    {
        $handler = new CustomLockReleaseHandler(new ConcreteBusDispatcher($this->container), $this->container);
        $command = new UniqueUntilProcessingFailureJob;
        $this->assertTrue($this->locks->acquire($command));

        $handler->failed(['command' => serialize($command)], new RuntimeException, 'job-id', new FakeJob);

        $this->assertTrue($handler->onlyOwned);
        $this->assertTrue($this->locks->acquire(new UniqueUntilProcessingFailureJob));
    }

    public function testCommandShouldBeUniqueReturnsTrueForShouldBeUniqueInterface(): void
    {
        $handler = $this->createHandler();

        $command = new CallQueuedHandlerTestUniqueJob;

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUnique', [$command]));
    }

    public function testCommandShouldBeUniqueReturnsTrueForCallQueuedListenerWithShouldBeUnique(): void
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUnique = true;

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUnique', [$listener]));
    }

    public function testCommandShouldBeUniqueReturnsFalseForCallQueuedListenerWithoutShouldBeUnique(): void
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUnique = false;

        $this->assertFalse($this->invokeMethod($handler, 'commandShouldBeUnique', [$listener]));
    }

    public function testCommandShouldBeUniqueReturnsFalseForRegularCommand(): void
    {
        $handler = $this->createHandler();

        $command = new CallQueuedHandlerTestRegularJob;

        $this->assertFalse($this->invokeMethod($handler, 'commandShouldBeUnique', [$command]));
    }

    public function testCommandShouldBeUniqueUntilProcessingReturnsTrueForInterface(): void
    {
        $handler = $this->createHandler();

        $command = new CallQueuedHandlerTestUniqueUntilProcessingJob;

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUniqueUntilProcessing', [$command]));
    }

    public function testCommandShouldBeUniqueUntilProcessingReturnsTrueForCallQueuedListener(): void
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUniqueUntilProcessing = true;

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUniqueUntilProcessing', [$listener]));
    }

    public function testCommandShouldBeUniqueUntilProcessingReturnsFalseForCallQueuedListenerWithout(): void
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUniqueUntilProcessing = false;

        $this->assertFalse($this->invokeMethod($handler, 'commandShouldBeUniqueUntilProcessing', [$listener]));
    }

    public function testUniqueJobLockIsReleasedAfterProcessing(): void
    {
        $lock = Double::for(Lock::class)->strict();
        $lock->expects('forceRelease');

        $cache = Double::for(Cache::class, LockProvider::class)->strict();
        $cache->allows('lock')->returns($lock);

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('make')->with(Cache::class)->returns($cache);

        $dispatcher = Double::for(BusDispatcher::class)->strict();
        $dispatcher->allows('dispatchNow');
        $dispatcher->allows('getCommandHandler')->returns(null);

        $job = Double::for(Job::class)->strict();
        $job->allows('isReleased')->returns(false);
        $job->allows('hasFailed')->returns(false);
        $job->allows('isDeletedOrReleased')->returns(false);
        $job->expects('delete');

        // Serialize before setting the mock job (mocks aren't serializable)
        $command = new CallQueuedHandlerTestUniqueJob;
        $serialized = serialize($command);

        $handler = new CallQueuedHandler($dispatcher, $container->instance());
        $handler->call($job, ['command' => $serialized]);
    }

    public function testUniqueUntilProcessingRetryDoesNotReleaseLockAgain(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')->with(Cache::class)->never();

        $dispatcher = Double::for(BusDispatcher::class)->strict();
        $dispatcher->expects('dispatchNow');
        $dispatcher->allows('getCommandHandler')->returns(null);

        $job = Double::for(Job::class)->strict();
        $job->allows('isReleased')->returns(false);
        $job->allows('attempts')->returns(2);
        $job->allows('hasFailed')->returns(false);
        $job->allows('isDeletedOrReleased')->returns(false);
        $job->expects('delete');

        $command = new CallQueuedHandlerTestUniqueUntilProcessingJob;
        $serialized = serialize($command);

        $handler = new CallQueuedHandler($dispatcher, $container->instance());
        $handler->call($job, ['command' => $serialized]);
    }

    #[DataProvider('middlewareRetryLockOwners')]
    public function testMiddlewareFailureOnRetryReleasesOnlyItsOwnLockWithoutJobTraits(bool $replacementOwner): void
    {
        $command = new CallQueuedHandlerTestUniqueJobWithFailingMiddleware;
        $cache = new Repository(new WorkerArrayStore);
        $lock = $cache->lock(UniqueLock::getKey($command), 60, $replacementOwner ? 'replacement-owner' : 'original-owner');
        $this->assertTrue($lock->get());

        $failure = new RuntimeException('Middleware failed.');
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('make')->with(Cache::class)->returns($cache);
        $container->expects('make')->with('failing.middleware')
            ->returns(static fn (): never => throw $failure);

        $dispatcher = Double::for(BusDispatcher::class)->strict();
        $dispatcher->expects('dispatchNow')->never();

        $job = Double::for(Job::class)->strict();
        $job->allows('isReleased')->returns(false);
        $job->allows('attempts')->returns(2);
        $job->allows('payload')->returns([
            'illuminate:log:context' => [
                'hidden' => ['laravel_unique_job_lock_owner' => serialize('original-owner')],
            ],
        ]);

        $handler = new CallQueuedHandler($dispatcher, $container->instance());

        try {
            $handler->call($job, ['command' => serialize($command)]);
            $this->fail('The middleware exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($replacementOwner, $lock->isOwnedByCurrentProcess());
    }

    /**
     * Provide original and replacement lock owners.
     */
    public static function middlewareRetryLockOwners(): array
    {
        return [
            'original owner' => [false],
            'replacement owner' => [true],
        ];
    }

    public function testHandleModelNotFoundFailsJobWhenDeleteWhenMissingModelsIsFalse(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();

        $job = Double::for(Job::class)->strict();
        $job->allows('payload')->returns(['deleteWhenMissingModels' => false]);
        $job->expects('fail');

        $handler = new CallQueuedHandler(Double::for(BusDispatcher::class)->strict(), $container->instance());
        $this->invokeMethod($handler, 'handleModelNotFound', [$job, new \Hypervel\Database\Eloquent\ModelNotFoundException]);
    }

    public function testHandleModelNotFoundDeletesJobWhenDeleteWhenMissingModelsIsTrue(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('bound')->with(BatchRepository::class)->returns(false);

        $job = Double::for(Job::class)->strict();
        $job->allows('payload')->returns(['deleteWhenMissingModels' => true]);
        $job->allows('resolveQueuedJobClass')->returns(CallQueuedHandlerTestRegularJob::class);
        $job->expects('delete');
        $job->expects('fail')->never();

        $handler = new CallQueuedHandler(Double::for(BusDispatcher::class)->strict(), $container->instance());
        $this->invokeMethod($handler, 'handleModelNotFound', [$job, new \Hypervel\Database\Eloquent\ModelNotFoundException]);
    }

    public function testEnsureUniqueJobLockIsReleasedViaContextDoesNothingWithoutContext(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('bound')->never();
        $container->expects('make')->never();

        // No propagated context set — hasPropagated() returns false
        $handler = new CallQueuedHandler(Double::for(BusDispatcher::class)->strict(), $container->instance());
        $this->invokeMethod($handler, 'ensureUniqueJobLockIsReleasedViaContext', []);
    }

    public function testFailedMethodSetsJobInstanceWhenProvided(): void
    {
        $job = new FakeJob;

        try {
            $this->handler->failed(
                ['command' => serialize(new CallQueuedHandlerTestFailedJob)],
                new Exception('test'),
                'test-uuid',
                $job,
            );

            $this->assertSame($job, CallQueuedHandlerTestFailedJob::$failedJob);
        } finally {
            CallQueuedHandlerTestFailedJob::$failedJob = null;
        }
    }

    public function testRunningCommandIsAvailableWhileCommandIsProcessing(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();

        $dispatcher = Double::for(BusDispatcher::class)->strict();
        $dispatcher->allows('getCommandHandler')->returns(null);
        $dispatcher->expects('dispatchNow')->resolves(function ($command) {
            $command->handle();
        });

        $job = Double::for(Job::class)->strict();
        $job->allows('isReleased')->returns(false);
        $job->allows('hasFailed')->returns(false);
        $job->allows('isDeletedOrReleased')->returns(false);
        $job->expects('delete');

        $handler = new CallQueuedHandler($dispatcher, $container->instance());
        CallQueuedHandlerTestRunningCommandJob::$handler = $handler;
        CallQueuedHandlerTestRunningCommandJob::$runningCommandDuringMiddleware = null;

        try {
            $handler->call($job, ['command' => serialize(new CallQueuedHandlerTestRunningCommandJob)]);

            $this->assertInstanceOf(
                CallQueuedHandlerTestRunningCommandJob::class,
                CallQueuedHandlerTestRunningCommandJob::$runningCommandDuringMiddleware
            );
            $this->assertNull($handler->getRunningCommand());
        } finally {
            CallQueuedHandlerTestRunningCommandJob::$handler = null;
            CallQueuedHandlerTestRunningCommandJob::$runningCommandDuringMiddleware = null;
        }
    }

    public function testRunningCommandIsResetWhenCommandThrows(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();

        $dispatcher = Double::for(BusDispatcher::class)->strict();
        $dispatcher->allows('getCommandHandler')->returns(null);
        $dispatcher->expects('dispatchNow')->throws($exception = new RuntimeException('Command failed.'));

        $job = Double::for(Job::class)->strict();

        $handler = new CallQueuedHandler($dispatcher, $container->instance());

        try {
            $handler->call($job, ['command' => serialize(new CallQueuedHandlerTestRegularJob)]);

            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertNull($handler->getRunningCommand());
    }

    public function testRunningCommandStaysNullForDebouncedJobs(): void
    {
        $cache = Double::for(Cache::class)->strict();
        $cache->expects('get')->returns('new-owner');

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('make')->with(Cache::class)->returns($cache);
        $container->allows('bound')->with('events')->returns(false);

        $job = Double::for(Job::class)->strict();
        $job->expects('delete');

        $handler = new CallQueuedHandler(Double::for(BusDispatcher::class)->strict(), $container->instance());

        $handler->call($job, ['command' => serialize(new CallQueuedHandlerTestDebouncedJob)]);

        $this->assertNull($handler->getRunningCommand());
    }

    public function testNextChainJobIsDispatchedBeforeCurrentJobIsDeleted(): void
    {
        CallQueuedHandlerTestChainJob::$order = [];

        try {
            $dispatcher = Double::for(BusDispatcher::class)->strict();
            $dispatcher->allows('getCommandHandler')->returns(null);
            $dispatcher->expects('dispatchNow')->resolves(function (): void {
                CallQueuedHandlerTestChainJob::$order[] = 'handle';
            });

            $job = Double::for(Job::class)->strict();
            $job->allows('isReleased')->returns(false);
            $job->allows('hasFailed')->returns(false);
            $job->allows('isDeletedOrReleased')->returns(false);
            $job->expects('delete')->resolves(function (): void {
                CallQueuedHandlerTestChainJob::$order[] = 'delete';
            });

            (new CallQueuedHandler(
                $dispatcher,
                Double::for(ContainerContract::class, override: true)->strict()->instance(),
            ))->call($job, ['command' => serialize(new CallQueuedHandlerTestChainJob)]);

            $this->assertSame(['handle', 'chain', 'delete'], CallQueuedHandlerTestChainJob::$order);
        } finally {
            CallQueuedHandlerTestChainJob::$order = [];
        }
    }

    public function testConcurrentMappedHandlersReceiveTheirOwnJobInstance(): void
    {
        $container = new Container;
        $dispatcher = new ConcreteBusDispatcher($container);
        $dispatcher->map([
            CallQueuedHandlerTestMappedCommand::class => CallQueuedHandlerTestMappedHandler::class,
        ]);
        $handler = new CallQueuedHandler($dispatcher, $container);

        $firstReady = new Channel(1);
        $secondReady = new Channel(1);
        $firstJob = new FakeJob;
        $secondJob = new FakeJob;

        [$firstResolved, $secondResolved] = parallel([
            function () use ($handler, $firstJob, $firstReady, $secondReady) {
                $resolved = $this->invokeMethod(
                    $handler,
                    'resolveHandler',
                    [$firstJob, new CallQueuedHandlerTestMappedCommand]
                );

                $firstReady->push(true);

                if ($secondReady->pop(1.0) !== true) {
                    throw new RuntimeException('Timed out waiting for the second mapped handler.');
                }

                return $resolved;
            },
            function () use ($handler, $secondJob, $firstReady, $secondReady) {
                if ($firstReady->pop(1.0) !== true) {
                    throw new RuntimeException('Timed out waiting for the first mapped handler.');
                }

                $resolved = $this->invokeMethod(
                    $handler,
                    'resolveHandler',
                    [$secondJob, new CallQueuedHandlerTestMappedCommand]
                );
                $secondReady->push(true);

                return $resolved;
            },
        ]);

        $this->assertNotSame($firstResolved, $secondResolved);
        $this->assertSame($firstJob, $firstResolved->job);
        $this->assertSame($secondJob, $secondResolved->job);
    }

    private function createHandler(): CallQueuedHandler
    {
        return new CallQueuedHandler(
            Double::for(BusDispatcher::class)->strict(),
            Double::for(ContainerContract::class, override: true)->strict()->instance()
        );
    }

    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invoke($object, ...$args);
    }
}

class UniqueUntilProcessingFailureJob implements ShouldBeUniqueUntilProcessing
{
    use Queueable;
}

class LegacyUniqueFailureJob implements ShouldBeUnique
{
}

class CustomUniqueLock extends UniqueLock
{
    public bool $onlyOwned = false;

    /**
     * Record the ownership requirement and release the lock.
     */
    public function release(mixed $job, bool $onlyOwned = false, string $owner = ''): void
    {
        $this->onlyOwned = $onlyOwned;

        parent::release($job, $onlyOwned, $owner);
    }
}

class CustomLockReleaseHandler extends CallQueuedHandler
{
    public bool $onlyOwned = false;

    /**
     * Record the ownership requirement and release the job's lock.
     */
    protected function ensureUniqueJobLockIsReleased(mixed $command, bool $onlyOwned = false, ?Job $job = null): void
    {
        $this->onlyOwned = $onlyOwned;

        parent::ensureUniqueJobLockIsReleased($command, $onlyOwned, $job);
    }
}

class CallQueuedHandlerTestUniqueJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
    }
}

class CallQueuedHandlerTestUniqueUntilProcessingJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
    }
}

class CallQueuedHandlerTestUniqueJobWithFailingMiddleware implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    public array $middleware = ['failing.middleware'];

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class CallQueuedHandlerTestRegularJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
    }
}

class CallQueuedHandlerTestFailedJob extends CallQueuedHandlerTestRegularJob
{
    public static ?Job $failedJob = null;

    /**
     * Record the injected job when failure handling runs.
     */
    public function failed(): void
    {
        self::$failedJob = $this->job;
    }
}

class CallQueuedHandlerTestMappedCommand
{
}

class CallQueuedHandlerTestChainJob
{
    /** @var list<string> */
    public static array $order = [];

    public function dispatchNextJobInChain(): void
    {
        self::$order[] = 'chain';
    }
}

class CallQueuedHandlerTestMappedHandler
{
    use InteractsWithQueue;
}

class CallQueuedHandlerTestRunningCommandJob implements ShouldQueue
{
    public static ?CallQueuedHandler $handler = null;

    public static mixed $runningCommandDuringMiddleware = null;

    public function middleware(): array
    {
        return [
            function (self $command, callable $next): mixed {
                self::$runningCommandDuringMiddleware = self::$handler?->getRunningCommand();

                return $next($command);
            },
        ];
    }

    public function handle(): void
    {
    }
}

class CallQueuedHandlerTestDebouncedJob implements ShouldQueue
{
    public string $debounceOwner = 'old-owner';

    public function handle(): void
    {
    }
}
