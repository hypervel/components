<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\CallQueuedHandlerTest;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
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
            'command' => serialize(new CallQueuedHandlerTestJob()),
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
            'command' => serialize($command = new CallQueuedHandlerTestJobWithMiddleware()),
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

        $command = new CallQueuedHandlerTestJobWithMiddleware();
        $command->through([new TestJobMiddleware()]);

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
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete()),
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
            'command' => serialize(new CallQueuedHandlerExceptionThrower()),
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
            'command' => serialize(new CallQueuedHandlerAttributeExceptionThrower()),
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

        $serialized = serialize((new CallQueuedHandlerBatchableExceptionThrower())->withBatchId('test-batch-id'));

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

        \Hypervel\Context\CoroutineContext::propagated()->addHidden([
            'laravel_unique_job_cache_store' => 'array',
            'laravel_unique_job_key' => 'laravel_unique_job:TestJob:42',
        ]);

        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['deleteWhenMissingModels' => false]);
        $job->shouldReceive('fail')->once();

        $instance->call($job, [
            'command' => serialize(new CallQueuedHandlerExceptionThrowerWithoutDelete()),
        ]);
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
