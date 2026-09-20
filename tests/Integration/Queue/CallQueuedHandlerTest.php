<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\CallQueuedHandlerTest;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Log\Context\Repository as ContextRepository;
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
    public function testJobCanBeDispatched(): void
    {
        CallQueuedHandlerTestJob::$handled = false;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerTestJob),
        ]);

        $this->assertTrue(CallQueuedHandlerTestJob::$handled);
    }

    public function testJobCanBeDispatchedThroughMiddleware(): void
    {
        CallQueuedHandlerTestJobWithMiddleware::$handled = false;
        CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand = null;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($command = new CallQueuedHandlerTestJobWithMiddleware),
        ]);

        $this->assertInstanceOf(CallQueuedHandlerTestJobWithMiddleware::class, CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand);
        $this->assertTrue(CallQueuedHandlerTestJobWithMiddleware::$handled);
    }

    public function testJobCanBeDispatchedThroughMiddlewareOnDispatch(): void
    {
        $_SERVER['__test.dispatchMiddleware'] = false;
        CallQueuedHandlerTestJobWithMiddleware::$handled = false;
        CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand = null;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $command = new CallQueuedHandlerTestJobWithMiddleware;
        $command->through([new TestJobMiddleware]);

        try {
            $instance->call($job, [
                'command' => serialize($command),
            ]);

            $this->assertInstanceOf(CallQueuedHandlerTestJobWithMiddleware::class, CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand);
            $this->assertTrue(CallQueuedHandlerTestJobWithMiddleware::$handled);
            $this->assertTrue($_SERVER['__test.dispatchMiddleware']);
        } finally {
            unset($_SERVER['__test.dispatchMiddleware']);
        }
    }

    public function testJobIsMarkedAsFailedIfModelNotFoundExceptionIsThrown(): void
    {
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->expects('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->expects('fail');

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
        ]);
    }

    public function testJobIsDeletedIfHasDeleteProperty(): void
    {
        Event::fake();

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->expects('payload')->andReturn(['deleteWhenMissingModels' => true]);
        $job->expects('resolveQueuedJobClass')->andReturn(CallQueuedHandlerExceptionThrower::class);
        $job->shouldReceive('markAsFailed')->never();
        $job->expects('delete');
        $job->shouldReceive('failed')->never();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrower),
        ]);

        Event::assertNotDispatched(JobFailed::class);
    }

    public function testJobIsDeletedIfHasDeleteAttribute(): void
    {
        Event::fake();

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->expects('payload')->andReturn(['deleteWhenMissingModels' => true]);
        $job->expects('resolveQueuedJobClass')->andReturn(CallQueuedHandlerAttributeExceptionThrower::class);
        $job->shouldReceive('markAsFailed')->never();
        $job->expects('delete');
        $job->shouldReceive('failed')->never();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerAttributeExceptionThrower),
        ]);

        Event::assertNotDispatched(JobFailed::class);
    }

    public function testBatchJobIsRecordedWhenDeletedDueToMissingModel(): void
    {
        Event::fake();

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $batch = m::mock(Batch::class);
        $batch->expects('recordSuccessfulJob')->with('job-uuid');

        $repository = m::mock(BatchRepository::class);
        $repository->expects('find')->with('test-batch-id')->andReturn($batch);
        $this->app->instance(BatchRepository::class, $repository);

        $serialized = serialize((new CallQueuedHandlerBatchableExceptionThrower)->withBatchId('test-batch-id'));

        $job = m::mock(Job::class);
        $job->expects('resolveQueuedJobClass')->andReturn(CallQueuedHandlerBatchableExceptionThrower::class);
        $job->shouldReceive('markAsFailed')->never();
        $job->expects('delete');
        $job->shouldReceive('failed')->never();
        $job->expects('uuid')->times(3)->andReturn('job-uuid');
        $job->expects('payload')->times(2)->andReturn([
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

    public function testUniqueJobLockIsReleasedViaContextOnModelNotFound(): void
    {
        $lock = m::mock(Lock::class);
        $lock->expects('forceRelease');

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with('laravel_unique_job:TestJob:42')->andReturn($lock);

        $cacheFactory = m::mock(CacheFactory::class);
        $cacheFactory->shouldReceive('store')->with('array')->andReturn($cache);
        $this->app->instance(CacheFactory::class, $cacheFactory);

        ContextRepository::getInstance()->addHidden([
            'laravel_unique_job_cache_store' => 'array',
            'laravel_unique_job_key' => 'laravel_unique_job:TestJob:42',
        ]);

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->expects('fail');

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
        ]);
    }

    public function testUniqueJobLockOwnerIsRestoredViaContextOnModelNotFound(): void
    {
        $lock = m::mock(Lock::class);
        $lock->expects('release');

        $cache = m::mock(Cache::class);
        $cache->expects('getStore')->andReturn(new WorkerArrayStore);
        $cache->expects('restoreLock')
            ->with('laravel_unique_job:TestJob:42', 'owner-token')
            ->andReturn($lock);
        $cache->shouldNotReceive('lock');

        $cacheFactory = m::mock(CacheFactory::class);
        $cacheFactory->shouldReceive('store')->with('array')->andReturn($cache);
        $this->app->instance(CacheFactory::class, $cacheFactory);

        ContextRepository::getInstance()->addHidden([
            'laravel_unique_job_cache_store' => 'array',
            'laravel_unique_job_key' => 'laravel_unique_job:TestJob:42',
            'laravel_unique_job_lock_owner' => 'owner-token',
        ]);

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->expects('fail');

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
        ]);
    }

    public function testDebouncedJobEventIsSkippedWithoutListeners(): void
    {
        $events = m::mock(EventDispatcher::class);
        $events->expects('hasListeners')->with(JobDebounced::class)->andReturnFalse();
        $events->shouldNotReceive('dispatch');
        $this->app->instance('events', $events);

        $job = m::mock(Job::class);
        $job->shouldNotReceive('getConnectionName');
        $job->expects('delete');

        $handler = new TestableCallQueuedHandler(new Dispatcher($this->app), $this->app);
        $handler->deleteDebounced($job, new stdClass);
    }

    public function testDebouncedJobOwnerIsCheckedWithOneCacheRead(): void
    {
        $command = new class {
            public string $debounceOwner = 'old-owner';

            /**
             * Get the debounce identifier.
             */
            public function debounceId(): string
            {
                return 'entity-1';
            }
        };

        $cache = m::mock(Cache::class);
        $cache->expects('get')->with(DebounceLock::getKey($command))->andReturn('new-owner');
        $this->app->instance(Cache::class, $cache);

        $handler = new TestableCallQueuedHandler(new Dispatcher($this->app), $this->app);

        $this->assertTrue($handler->shouldDebounce($command));
    }

    public function testDebouncedJobEventRemainsVisibleToEventFake(): void
    {
        Event::fake([JobDebounced::class]);

        $job = m::mock(Job::class);
        $job->expects('getConnectionName')->andReturn('database');
        $job->expects('delete');

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
    /**
     * Determine whether the command was superseded.
     */
    public function shouldDebounce(mixed $command): bool
    {
        return $this->commandShouldBeDebounced($command);
    }

    /**
     * Delete a superseded job.
     */
    public function deleteDebounced(Job $job, mixed $command): void
    {
        $this->deleteDebouncedJob($job, $command);
    }
}

class CallQueuedHandlerTestJob
{
    use InteractsWithQueue;

    public static bool $handled = false;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$handled = true;
    }
}

/** This exists to test that middleware can also be defined in base classes */
abstract class AbstractCallQueuedHandlerTestJobWithMiddleware
{
    public static mixed $middlewareCommand = null;

    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [
            new class {
                /**
                 * Record and pass the command to the next middleware.
                 */
                public function handle(object $command, callable $next): mixed
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

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$handled = true;
    }
}

class CallQueuedHandlerExceptionThrower
{
    public bool $deleteWhenMissingModels = true;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }

    /**
     * Fail to restore a missing model.
     */
    public function __wakeup(): void
    {
        throw new ModelNotFoundException('Foo');
    }
}

class CallQueuedHandlerExceptionThrowerWithoutDelete
{
    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }

    /**
     * Fail to restore a missing model.
     */
    public function __wakeup(): void
    {
        throw new ModelNotFoundException('Foo');
    }
}

#[DeleteWhenMissingModels]
class CallQueuedHandlerAttributeExceptionThrower
{
    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }

    /**
     * Fail to restore a missing model.
     */
    public function __wakeup(): void
    {
        throw new ModelNotFoundException('Foo');
    }
}

#[DeleteWhenMissingModels]
class CallQueuedHandlerBatchableExceptionThrower
{
    use Batchable;
    use InteractsWithQueue;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }

    /**
     * Fail to restore a missing model.
     */
    public function __wakeup(): void
    {
        throw new ModelNotFoundException('Foo');
    }
}

class TestJobMiddleware
{
    /**
     * Record and pass the command to the next middleware.
     */
    public function handle(object $command, callable $next): mixed
    {
        $_SERVER['__test.dispatchMiddleware'] = true;

        return $next($command);
    }
}
