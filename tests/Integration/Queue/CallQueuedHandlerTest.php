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
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Events\JobDebounced;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Queue\Jobs\Job as BaseJob;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;

class CallQueuedHandlerTest extends TestCase
{
    public function testJobCanBeDispatched(): void
    {
        CallQueuedHandlerTestJob::$handled = false;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerTestJob),
        ]);

        $this->assertTrue($job->isDeleted());

        $this->assertTrue(CallQueuedHandlerTestJob::$handled);
    }

    public function testJobCanBeDispatchedThroughMiddleware(): void
    {
        CallQueuedHandlerTestJobWithMiddleware::$handled = false;
        CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand = null;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize($command = new CallQueuedHandlerTestJobWithMiddleware),
        ]);

        $this->assertTrue($job->isDeleted());

        $this->assertInstanceOf(CallQueuedHandlerTestJobWithMiddleware::class, CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand);
        $this->assertTrue(CallQueuedHandlerTestJobWithMiddleware::$handled);
    }

    public function testJobCanBeDispatchedThroughMiddlewareOnDispatch(): void
    {
        $_SERVER['__test.dispatchMiddleware'] = false;
        CallQueuedHandlerTestJobWithMiddleware::$handled = false;
        CallQueuedHandlerTestJobWithMiddleware::$middlewareCommand = null;

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $command = new CallQueuedHandlerTestJobWithMiddleware;
        $command->through([new TestJobMiddleware]);

        try {
            $instance->call($job, [
                'command' => serialize($command),
            ]);

            $this->assertTrue($job->isDeleted());

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

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
        ]);

        $this->assertTrue($job->hasFailed());
        $this->assertInstanceOf(ModelNotFoundException::class, $job->failedWith);
        $this->assertFalse($job->isDeleted());
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
        $job->shouldReceive('fail')->never();

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
        $job->shouldReceive('fail')->never();

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
        $job->shouldReceive('fail')->never();
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

    public function testBatchJobIsRecordedAsFailedWhenModelIsMissing(): void
    {
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $exception = new ModelNotFoundException('Foo');

        $batch = m::mock(Batch::class);
        $batch->expects('recordFailedJob')->with('job-uuid', $exception);

        $repository = m::mock(BatchRepository::class);
        $repository->expects('find')->with('test-batch-id')->andReturn($batch);
        $this->app->instance(BatchRepository::class, $repository);

        $this->expectException(ModelNotFoundException::class);

        $instance->failed([
            'batchId' => 'test-batch-id',
            'command' => serialize((new CallQueuedHandlerBatchableExceptionThrower)->withBatchId('test-batch-id')),
        ], $exception, 'job-uuid');
    }

    public function testUniqueJobLockIsReleasedFromPayloadWhenDeletedForMissingModel(): void
    {
        $lock = m::mock(Lock::class);
        $lock->expects('forceRelease');

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with('laravel_unique_job:TestJob:42')->andReturn($lock);

        $cacheFactory = m::mock(CacheFactory::class);
        $cacheFactory->shouldReceive('store')->with('array')->andReturn($cache);
        $this->app->instance(CacheFactory::class, $cacheFactory);

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'deleteWhenMissingModels' => true,
            'illuminate:log:context' => ['hidden' => $this->uniqueLockMetadata()],
        ]);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->expects('resolveQueuedJobClass')->andReturn(CallQueuedHandlerExceptionThrower::class);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrower),
        ]);
    }

    public function testUniqueJobLockOwnerIsRestoredFromPayloadWhenDeletedForMissingModel(): void
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

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'deleteWhenMissingModels' => true,
            'illuminate:log:context' => ['hidden' => $this->uniqueLockMetadata('owner-token')],
        ]);
        $job->expects('resolveQueuedJobClass')->andReturn(CallQueuedHandlerExceptionThrower::class);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrower),
        ]);
    }

    #[TestWith([true], 'payload metadata')]
    #[TestWith([false], 'no payload metadata')]
    public function testUniqueJobLockIsReleasedOnlyFromThePayloadWhenFailingWithMissingModel(bool $payloadHasLockMetadata): void
    {
        $cache = $this->app->make(CacheFactory::class)->store('worker-array');
        $this->assertTrue($cache->lock('laravel_unique_job:TestJob:42')->acquire());
        $this->assertTrue($cache->lock('laravel_unique_job:Other:1')->acquire());

        // Another job's metadata in the ambient context, as in the worker's timeout monitor.
        ContextRepository::getInstance()->addHidden([
            'laravel_unique_job_cache_store' => 'worker-array',
            'laravel_unique_job_key' => 'laravel_unique_job:Other:1',
        ]);

        $job = new CallQueuedHandlerMissingModelQueueJob($this->app, $this->missingModelPayload(
            CallQueuedHandlerMissingModelUniqueJob::class,
            $payloadHasLockMetadata ? $this->uniqueLockMetadata(store: 'worker-array') : [],
        ));

        try {
            $this->app->make(CallQueuedHandler::class)->failed($job->payload()['data'], new RuntimeException, 'job-uuid', $job);
            $this->fail('The missing model was not reported.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame($payloadHasLockMetadata, $cache->lock('laravel_unique_job:TestJob:42')->acquire());
        $this->assertFalse($cache->lock('laravel_unique_job:Other:1')->acquire());
    }

    public function testOwnerlessUniqueUntilProcessingLockIsKeptWhenFailingWithMissingModelAfterProcessingMayHaveStarted(): void
    {
        $cache = $this->app->make(CacheFactory::class)->store('worker-array');

        // A replacement dispatch holds the lock the failed job released when its attempt began processing.
        $this->assertTrue($cache->lock('laravel_unique_job:TestJob:42')->acquire());

        $job = new CallQueuedHandlerMissingModelQueueJob($this->app, $this->missingModelPayload(
            CallQueuedHandlerMissingModelUniqueUntilProcessingJob::class,
            $this->uniqueLockMetadata(store: 'worker-array'),
        ));

        try {
            $this->app->make(CallQueuedHandler::class)->failed($job->payload()['data'], new RuntimeException, 'job-uuid', $job);
            $this->fail('The missing model was not reported.');
        } catch (ModelNotFoundException) {
        }

        $this->assertFalse($cache->lock('laravel_unique_job:TestJob:42')->acquire());
    }

    public function testOwnerlessRegularUniqueListenerLockIsReleasedWhenFailingWithMissingModel(): void
    {
        $cache = $this->app->make(CacheFactory::class)->store('worker-array');
        $this->assertTrue($cache->lock('laravel_unique_job:TestJob:42')->acquire());

        $payload = $this->missingModelPayload(CallQueuedListener::class, $this->uniqueLockMetadata(store: 'worker-array'));
        $payload['displayName'] = CallQueuedHandlerUniqueListener::class;

        $job = new CallQueuedHandlerMissingModelQueueJob($this->app, $payload);

        try {
            $this->app->make(CallQueuedHandler::class)->failed($payload['data'], new RuntimeException, 'job-uuid', $job);
            $this->fail('The missing model was not reported.');
        } catch (ModelNotFoundException) {
        }

        $this->assertTrue($cache->lock('laravel_unique_job:TestJob:42')->acquire());
    }

    #[TestWith([1, 1])]
    #[TestWith([2, 0])]
    public function testMissingModelFailureReleasesAnOwnerlessUniqueUntilProcessingLockOnceBeforeProcessing(int $attempts, int $releases): void
    {
        $lock = m::mock(Lock::class);
        $lock->expects('forceRelease')->times($releases);

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with('laravel_unique_job:TestJob:42')->andReturn($lock);

        $cacheFactory = m::mock(CacheFactory::class);
        $cacheFactory->shouldReceive('store')->with('array')->andReturn($cache);
        $this->app->instance(CacheFactory::class, $cacheFactory);

        $job = new CallQueuedHandlerMissingModelQueueJob($this->app, $this->missingModelPayload(
            CallQueuedHandlerMissingModelUniqueUntilProcessingJob::class,
            $this->uniqueLockMetadata(),
        ), $attempts);

        try {
            $this->app->make(CallQueuedHandler::class)->call($job, $job->payload()['data']);
            $this->fail('The missing model was not reported.');
        } catch (ModelNotFoundException) {
        }

        $this->assertTrue($job->hasFailed());
    }

    #[TestWith([CallQueuedHandlerMissingModelUniqueJob::class, '', true], 'ownerless unique job')]
    #[TestWith([CallQueuedHandlerMissingModelUniqueUntilProcessingJob::class, 'owner-token', true], 'owned unique until processing job')]
    #[TestWith([CallQueuedHandlerMissingModelUniqueUntilProcessingJob::class, '', false], 'ownerless unique until processing job')]
    public function testUniqueJobLockIsReleasedFromContextWhenFailingWithMissingModelWithoutAJob(string $commandName, string $owner, bool $released): void
    {
        $cache = $this->app->make(CacheFactory::class)->store('worker-array');
        $this->assertTrue($cache->lock('laravel_unique_job:TestJob:42', 0, 'owner-token')->acquire());

        ContextRepository::getInstance()->addHidden([
            'laravel_unique_job_cache_store' => 'worker-array',
            'laravel_unique_job_key' => 'laravel_unique_job:TestJob:42',
            'laravel_unique_job_lock_owner' => $owner,
        ]);

        try {
            $this->app->make(CallQueuedHandler::class)->failed([
                'commandName' => $commandName,
                'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
            ], new RuntimeException, 'job-uuid');
            $this->fail('The missing model was not reported.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame($released, $cache->lock('laravel_unique_job:TestJob:42')->acquire());
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

    /**
     * Get the payload metadata of a unique job lock.
     *
     * @return array<string, string>
     */
    protected function uniqueLockMetadata(string $owner = '', string $store = 'array'): array
    {
        return [
            'laravel_unique_job_cache_store' => serialize($store),
            'laravel_unique_job_key' => serialize('laravel_unique_job:TestJob:42'),
            'laravel_unique_job_lock_owner' => serialize($owner),
        ];
    }

    /**
     * Get the payload of a queued command whose model is missing.
     *
     * @param class-string $commandName
     * @param array<string, string> $hidden
     */
    protected function missingModelPayload(string $commandName, array $hidden): array
    {
        return [
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'uuid' => 'job-uuid',
            'displayName' => $commandName,
            'deleteWhenMissingModels' => false,
            'data' => [
                'commandName' => $commandName,
                'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete),
            ],
            'illuminate:log:context' => ['hidden' => $hidden],
        ];
    }
}

class CallQueuedHandlerMissingModelQueueJob extends BaseJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(ContainerContract $container, protected array $rawPayload, protected int $attemptCount = 1)
    {
        $this->container = $container;
        $this->connectionName = 'sync';
        $this->queue = 'default';
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return 'job-id';
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return json_encode($this->rawPayload, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return $this->attemptCount;
    }
}

class CallQueuedHandlerMissingModelUniqueJob implements ShouldBeUnique
{
}

class CallQueuedHandlerMissingModelUniqueUntilProcessingJob implements ShouldBeUniqueUntilProcessing
{
}

class CallQueuedHandlerUniqueListener implements ShouldBeUnique, ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(): void
    {
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
