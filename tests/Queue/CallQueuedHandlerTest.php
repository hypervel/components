<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\Dispatcher as ConcreteBusDispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcher;
use Hypervel\Contracts\Cache\Lock;
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
use Mockery as m;
use ReflectionMethod;
use RuntimeException;
use Swoole\Coroutine\Channel;

use function Hypervel\Coroutine\parallel;

class CallQueuedHandlerTest extends TestCase
{
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
        $lock = m::mock(Lock::class);
        $lock->shouldReceive('forceRelease')->once();

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->andReturn($lock);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(Cache::class)->andReturn($cache);

        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('dispatchNow');
        $dispatcher->shouldReceive('getCommandHandler')->andReturn(null);

        $job = m::mock(Job::class);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        // Serialize before setting the mock job (mocks aren't serializable)
        $command = new CallQueuedHandlerTestUniqueJob;
        $serialized = serialize($command);

        $handler = new CallQueuedHandler($dispatcher, $container);
        $handler->call($job, ['command' => $serialized]);
    }

    public function testUniqueUntilProcessingRetryDoesNotReleaseLockAgain(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(Cache::class)->never();

        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('dispatchNow')->once();
        $dispatcher->shouldReceive('getCommandHandler')->andReturn(null);

        $job = m::mock(Job::class);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('attempts')->andReturn(2);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $command = new CallQueuedHandlerTestUniqueUntilProcessingJob;
        $serialized = serialize($command);

        $handler = new CallQueuedHandler($dispatcher, $container);
        $handler->call($job, ['command' => $serialized]);
    }

    public function testHandleModelNotFoundFailsJobWhenDeleteWhenMissingModelsIsFalse(): void
    {
        $container = m::mock(ContainerContract::class);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->shouldReceive('fail')->once();

        $handler = new CallQueuedHandler(m::mock(BusDispatcher::class), $container);
        $this->invokeMethod($handler, 'handleModelNotFound', [$job, new \Hypervel\Database\Eloquent\ModelNotFoundException]);
    }

    public function testHandleModelNotFoundDeletesJobWhenDeleteWhenMissingModelsIsTrue(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with(BatchRepository::class)->andReturn(false);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => true]);
        $job->shouldReceive('resolveQueuedJobClass')->andReturn(CallQueuedHandlerTestRegularJob::class);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('fail')->never();

        $handler = new CallQueuedHandler(m::mock(BusDispatcher::class), $container);
        $this->invokeMethod($handler, 'handleModelNotFound', [$job, new \Hypervel\Database\Eloquent\ModelNotFoundException]);
    }

    public function testEnsureUniqueJobLockIsReleasedViaContextDoesNothingWithoutContext(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->never();
        $container->shouldReceive('make')->never();

        // No propagated context set — hasPropagated() returns false
        $handler = new CallQueuedHandler(m::mock(BusDispatcher::class), $container);
        $this->invokeMethod($handler, 'ensureUniqueJobLockIsReleasedViaContext', []);
    }

    public function testFailedMethodSetsJobInstanceWhenProvided(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(Cache::class)->andReturn(m::mock(Cache::class));

        $dispatcher = m::mock(BusDispatcher::class);

        $command = new CallQueuedHandlerTestRegularJob;
        $serialized = serialize($command);

        $job = m::mock(Job::class);

        $handler = new CallQueuedHandler($dispatcher, $container);
        $handler->failed(['command' => $serialized], new Exception('test'), 'test-uuid', $job);

        // If we get here without error, the job was set successfully
        $this->assertTrue(true);
    }

    public function testRunningCommandIsAvailableWhileCommandIsProcessing(): void
    {
        $container = m::mock(ContainerContract::class);

        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('getCommandHandler')->andReturn(null);
        $dispatcher->shouldReceive('dispatchNow')->once()->andReturnUsing(function ($command) {
            $command->handle();
        });

        $job = m::mock(Job::class);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $handler = new CallQueuedHandler($dispatcher, $container);
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
        $container = m::mock(ContainerContract::class);

        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('getCommandHandler')->andReturn(null);
        $dispatcher->shouldReceive('dispatchNow')->once()->andThrow($exception = new RuntimeException('Command failed.'));

        $job = m::mock(Job::class);

        $handler = new CallQueuedHandler($dispatcher, $container);

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
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')->once()->andReturn('new-owner');

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(Cache::class)->andReturn($cache);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $job = m::mock(Job::class);
        $job->shouldReceive('delete')->once();

        $handler = new CallQueuedHandler(m::mock(BusDispatcher::class), $container);

        $handler->call($job, ['command' => serialize(new CallQueuedHandlerTestDebouncedJob)]);

        $this->assertNull($handler->getRunningCommand());
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
            m::mock(BusDispatcher::class),
            m::mock(ContainerContract::class)
        );
    }

    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invoke($object, ...$args);
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

class CallQueuedHandlerTestRegularJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
    }
}

class CallQueuedHandlerTestMappedCommand
{
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
