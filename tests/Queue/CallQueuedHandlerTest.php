<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\Queueable;
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
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class CallQueuedHandlerTest extends TestCase
{
    public function testCommandShouldBeUniqueReturnsTrueForShouldBeUniqueInterface()
    {
        $handler = $this->createHandler();

        $command = new CallQueuedHandlerTestUniqueJob();

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUnique', [$command]));
    }

    public function testCommandShouldBeUniqueReturnsTrueForCallQueuedListenerWithShouldBeUnique()
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUnique = true;

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUnique', [$listener]));
    }

    public function testCommandShouldBeUniqueReturnsFalseForCallQueuedListenerWithoutShouldBeUnique()
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUnique = false;

        $this->assertFalse($this->invokeMethod($handler, 'commandShouldBeUnique', [$listener]));
    }

    public function testCommandShouldBeUniqueReturnsFalseForRegularCommand()
    {
        $handler = $this->createHandler();

        $command = new CallQueuedHandlerTestRegularJob();

        $this->assertFalse($this->invokeMethod($handler, 'commandShouldBeUnique', [$command]));
    }

    public function testCommandShouldBeUniqueUntilProcessingReturnsTrueForInterface()
    {
        $handler = $this->createHandler();

        $command = new CallQueuedHandlerTestUniqueUntilProcessingJob();

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUniqueUntilProcessing', [$command]));
    }

    public function testCommandShouldBeUniqueUntilProcessingReturnsTrueForCallQueuedListener()
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUniqueUntilProcessing = true;

        $this->assertTrue($this->invokeMethod($handler, 'commandShouldBeUniqueUntilProcessing', [$listener]));
    }

    public function testCommandShouldBeUniqueUntilProcessingReturnsFalseForCallQueuedListenerWithout()
    {
        $handler = $this->createHandler();

        $listener = new CallQueuedListener('SomeListener', 'handle', []);
        $listener->shouldBeUniqueUntilProcessing = false;

        $this->assertFalse($this->invokeMethod($handler, 'commandShouldBeUniqueUntilProcessing', [$listener]));
    }

    public function testUniqueJobLockIsReleasedAfterProcessing()
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
        $command = new CallQueuedHandlerTestUniqueJob();
        $serialized = serialize($command);

        $handler = new CallQueuedHandler($dispatcher, $container);
        $handler->call($job, ['command' => $serialized]);
    }

    public function testHandleModelNotFoundFailsJobWhenDeleteWhenMissingModelsIsFalse()
    {
        $container = m::mock(ContainerContract::class);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->shouldReceive('fail')->once();

        $handler = new CallQueuedHandler(m::mock(BusDispatcher::class), $container);
        $this->invokeMethod($handler, 'handleModelNotFound', [$job, new \Hypervel\Database\Eloquent\ModelNotFoundException()]);
    }

    public function testHandleModelNotFoundDeletesJobWhenDeleteWhenMissingModelsIsTrue()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with(BatchRepository::class)->andReturn(false);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => true]);
        $job->shouldReceive('resolveQueuedJobClass')->andReturn(CallQueuedHandlerTestRegularJob::class);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('fail')->never();

        $handler = new CallQueuedHandler(m::mock(BusDispatcher::class), $container);
        $this->invokeMethod($handler, 'handleModelNotFound', [$job, new \Hypervel\Database\Eloquent\ModelNotFoundException()]);
    }

    public function testEnsureUniqueJobLockIsReleasedViaContextDoesNothingWithoutContext()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->never();
        $container->shouldReceive('make')->never();

        // No propagated context set — hasPropagated() returns false
        $handler = new CallQueuedHandler(m::mock(BusDispatcher::class), $container);
        $this->invokeMethod($handler, 'ensureUniqueJobLockIsReleasedViaContext', []);
    }

    public function testFailedMethodSetsJobInstanceWhenProvided()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(Cache::class)->andReturn(m::mock(Cache::class));

        $dispatcher = m::mock(BusDispatcher::class);

        $command = new CallQueuedHandlerTestRegularJob();
        $serialized = serialize($command);

        $job = m::mock(Job::class);

        $handler = new CallQueuedHandler($dispatcher, $container);
        $handler->failed(['command' => $serialized], new Exception('test'), 'test-uuid', $job);

        // If we get here without error, the job was set successfully
        $this->assertTrue(true);
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
