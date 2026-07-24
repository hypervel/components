<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\CallQueuedHandlerTest;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Events\JobDebounced;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use stdClass;

class CallQueuedHandlerTest extends TestCase
{
    public function testJobCanBeDispatched()
    {
        CallQueuedHandlerTestJob::$handled = false;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerTestJob),
        ]);

        $this->assertTrue(CallQueuedHandlerTestJob::$handled);
    }

    public function testJobCanBeDispatchedThroughMiddleware()
    {
        CallQueuedHandlerTestJobWithMiddleware::$handled = false;
        CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand = null;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($command = new CallQueuedHandlerTestJobWithMiddleware),
        ]);

        $this->assertInstanceOf(CallQueuedHandlerTestJobWithMiddleware::class, CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand);
        $this->assertTrue(CallQueuedHandlerTestJobWithMiddleware::$handled);
    }

    public function testJobCanBeDispatchedThroughMiddlewareOnDispatch()
    {
        $_SERVER['__test.dispatchMiddleware'] = false;
        CallQueuedHandlerTestJobWithMiddleware::$handled = false;
        CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand = null;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $command = new CallQueuedHandlerTestJobWithMiddleware;
        $command->through([new TestJobMiddleware]);

        $instance->call($job, [
            'command' => serialize($command),
        ]);

        $this->assertInstanceOf(CallQueuedHandlerTestJobWithMiddleware::class, CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand);
        $this->assertTrue(CallQueuedHandlerTestJobWithMiddleware::$handled);
        $this->assertTrue($_SERVER['__test.dispatchMiddleware']);
    }

    public function testJobIsMarkedAsFailedIfModelNotFoundExceptionIsThrown()
    {
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->shouldReceive('fail')->once();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
        ]);
    }

    public function testJobIsDeletedIfHasDeleteProperty()
    {
        Event::fake();

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => true]);
        $job->shouldReceive('getConnectionName')->andReturn('connection');
        $job->shouldReceive('resolveQueuedJobClass')->andReturn(CallQueuedHandlerExceptionThrower::class);
        $job->shouldReceive('markAsFailed')->never();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('failed')->never();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrower),
        ]);

        Event::assertNotDispatched(JobFailed::class);
    }

    public function testJobIsDeletedIfHasDeleteAttribute()
    {
        Event::fake();

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => true]);
        $job->shouldReceive('getConnectionName')->andReturn('connection');
        $job->shouldReceive('resolveQueuedJobClass')->andReturn(CallQueuedHandlerAttributeExceptionThrower::class);
        $job->shouldReceive('markAsFailed')->never();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('failed')->never();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerAttributeExceptionThrower),
        ]);

        Event::assertNotDispatched(JobFailed::class);
    }

    public function testBatchJobIsRecordedWhenDeletedDueToMissingModel()
    {
        Event::fake();

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $batch = m::mock(Batch::class);
        $batch->shouldReceive('recordSuccessfulJob')->once()->with('job-uuid');

        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('find')->once()->with('test-batch-id')->andReturn($batch);
        $this->app->instance(BatchRepository::class, $repository);

        $serialized = serialize((new CallQueuedHandlerBatchableExceptionThrower)->withBatchId('test-batch-id'));

        $job = m::mock(Job::class);
        $job->shouldReceive('resolveQueuedJobClass')->andReturn(CallQueuedHandlerBatchableExceptionThrower::class);
        $job->shouldReceive('markAsFailed')->never();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('failed')->never();
        $job->shouldReceive('uuid')->andReturn('job-uuid');
        $job->shouldReceive('payload')->andReturn([
            'deleteWhenMissingModels' => true,
            'data' => [
                'batchId' => 'test-batch-id',
                'command' => $serialized,
            ],
        ]);

        $instance->call($job, [
            'command' => $serialized,
        ]);

        Event::assertNotDispatched(JobFailed::class);
    }

    public function testUniqueJobLockIsReleasedViaContextOnModelNotFound()
    {
        $lock = m::mock(\Hypervel\Contracts\Cache\Lock::class);
        $lock->shouldReceive('forceRelease')->once();

        $store = m::mock(\Hypervel\Contracts\Cache\Repository::class);
        $store->shouldReceive('lock')->with('laravel_unique_job:TestJob:42')->andReturn($lock);

        $cacheFactory = m::mock(\Hypervel\Contracts\Cache\Factory::class);
        $cacheFactory->shouldReceive('store')->with('array')->andReturn($store);
        $this->app->instance(\Hypervel\Contracts\Cache\Factory::class, $cacheFactory);

        \Hypervel\Log\Context\Repository::getInstance()->addHidden([
            'laravel_unique_job_cache_store' => 'array',
            'laravel_unique_job_key' => 'laravel_unique_job:TestJob:42',
        ]);

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->shouldReceive('fail')->once();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
        ]);
    }

    public function testDebouncedJobEventIsSkippedWithoutListeners(): void
    {
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(JobDebounced::class)->andReturnFalse();
        $events->shouldNotReceive('dispatch');
        $this->app->instance('events', $events);

        $job = m::mock(Job::class);
        $job->shouldNotReceive('getConnectionName');
        $job->shouldReceive('delete')->once();

        $handler = new TestableCallQueuedHandler(new Dispatcher($this->app), $this->app);
        $handler->deleteDebounced($job, new stdClass);
    }

    public function testDebouncedJobOwnerIsCheckedWithOneCacheRead(): void
    {
        $command = new class {
            public string $debounceOwner = 'old-owner';

            public function debounceId(): string
            {
                return 'entity-1';
            }
        };

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')->once()->with(DebounceLock::getKey($command))->andReturn('new-owner');
        $this->app->instance(Cache::class, $cache);

        $handler = new TestableCallQueuedHandler(new Dispatcher($this->app), $this->app);

        $this->assertTrue($handler->shouldDebounce($command));
    }

    public function testDebouncedJobEventRemainsVisibleToEventFake(): void
    {
        Event::fake([JobDebounced::class]);

        $job = m::mock(Job::class);
        $job->shouldReceive('getConnectionName')->once()->andReturn('database');
        $job->shouldReceive('delete')->once();

        $command = new stdClass;
        $handler = new TestableCallQueuedHandler(new Dispatcher($this->app), $this->app);
        $handler->deleteDebounced($job, $command);

        Event::assertDispatched(JobDebounced::class, function (JobDebounced $event) use ($job, $command): bool {
            return $event->connectionName === 'database'
                && $event->job === $job
                && $event->command === $command;
        });
    }
}

class TestableCallQueuedHandler extends CallQueuedHandler
{
    public function shouldDebounce(mixed $command): bool
    {
        return $this->commandShouldBeDebounced($command);
    }

    public function deleteDebounced(Job $job, mixed $command): void
    {
        $this->deleteDebouncedJob($job, $command);
    }
}

class CallQueuedHandlerTestJob
{
    use InteractsWithQueue;

    public static bool $handled = false;

    public function handle()
    {
        static::$handled = true;
    }
}

/** This exists to test that middleware can also be defined in base classes */
abstract class AbstractCallQueuedHandlerTestJobWithMiddleware
{
    public static mixed $middlewareCommand = null;

    public function middleware()
    {
        return [
            new class {
                public function handle($command, $next)
                {
                    AbstractCallQueuedHandlerTestJobWithMiddleware::$middlewareCommand = $command;

                    return $next($command);
                }
            },
        ];
    }
}

class CallQueuedHandlerTestJobWithMiddleware extends AbstractCallQueuedHandlerTestJobWithMiddleware
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public function handle()
    {
        static::$handled = true;
    }
}

class CallQueuedHandlerExceptionThrower
{
    public bool $deleteWhenMissingModels = true;

    public function handle()
    {
    }

    public function __wakeup()
    {
        throw new ModelNotFoundException('Foo');
    }
}

class CallQueuedHandlerExceptionThrowerWithoutDelete
{
    public function handle()
    {
    }

    public function __wakeup()
    {
        throw new ModelNotFoundException('Foo');
    }
}

#[DeleteWhenMissingModels]
class CallQueuedHandlerAttributeExceptionThrower
{
    public function handle()
    {
    }

    public function __wakeup()
    {
        throw new ModelNotFoundException('Foo');
    }
}

#[DeleteWhenMissingModels]
class CallQueuedHandlerBatchableExceptionThrower
{
    use Batchable;
    use InteractsWithQueue;

    public function handle()
    {
    }

    public function __wakeup()
    {
        throw new ModelNotFoundException('Foo');
    }
}

class TestJobMiddleware
{
    public function handle($command, $next)
    {
        $_SERVER['__test.dispatchMiddleware'] = true;

        return $next($command);
    }
}
