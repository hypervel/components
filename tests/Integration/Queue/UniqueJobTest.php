<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\UniqueJobTest;

use Exception;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\Events\UniqueJobSkipped;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Tests\Integration\Queue\QueueTestCase;

#[WithMigration]
#[WithMigration('cache')]
#[WithMigration('queue')]
class UniqueJobTest extends QueueTestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('cache.default', 'database');
        $config->set('queue.default', env('QUEUE_CONNECTION', 'database'));
    }

    public function testUniqueJobsAreNotDispatched(): void
    {
        Bus::fake();

        UniqueTestJob::dispatch();
        $this->runQueueWorkerCommand(['--once' => true]);
        Bus::assertDispatched(UniqueTestJob::class);

        $this->assertFalse(
            $this->app->get(Cache::class)->lock($this->getLockKey(UniqueTestJob::class), 10)->get()
        );

        Bus::assertDispatchedTimes(UniqueTestJob::class);
        UniqueTestJob::dispatch();
        $this->runQueueWorkerCommand(['--once' => true]);
        Bus::assertDispatchedTimes(UniqueTestJob::class);

        $this->assertFalse(
            $this->app->get(Cache::class)->lock($this->getLockKey(UniqueTestJob::class), 10)->get()
        );
    }

    public function testUniqueJobEmitsUniqueJobSkippedEventWhenAlreadyAcquired(): void
    {
        Bus::fake();

        $skipped = [];

        Event::listen(UniqueJobSkipped::class, function (UniqueJobSkipped $event) use (&$skipped): void {
            $skipped[] = $event->job;
        });

        UniqueTestJob::dispatch();

        $this->assertSame([], $skipped);

        UniqueTestJob::dispatch();

        $this->assertCount(1, $skipped);
        $this->assertInstanceOf(UniqueTestJob::class, $skipped[0]);
    }

    public function testUniqueJobWithViaDispatched(): void
    {
        Bus::fake();

        UniqueViaJob::dispatch();
        Bus::assertDispatched(UniqueViaJob::class);
    }

    public function testLockIsReleasedForSuccessfulJobs(): void
    {
        UniqueTestJob::$handled = false;
        dispatch($job = new UniqueTestJob);
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testLockIsReleasedForFailedJobs(): void
    {
        UniqueTestFailJob::$handled = false;

        $this->expectException(Exception::class);

        try {
            dispatch_sync($job = new UniqueTestFailJob);
        } finally {
            $this->assertTrue($job::$handled);
            $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
        }
    }

    public function testLockIsNotReleasedForJobRetries(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        UniqueTestRetryJob::$handled = false;

        dispatch($job = new UniqueTestRetryJob);

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        UniqueTestRetryJob::$handled = false;
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testLockIsNotReleasedForJobReleases(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        UniqueTestReleasedJob::$handled = false;
        dispatch($job = new UniqueTestReleasedJob);

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        UniqueTestReleasedJob::$handled = false;
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertFalse($job::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testLockCanBeReleasedBeforeProcessing(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        UniqueUntilStartTestJob::$handled = false;

        dispatch($job = new UniqueUntilStartTestJob);

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testRetryOfUniqueUntilProcessingJobDoesNotReleaseSubsequentLock(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        dispatch($job = new UniqueUntilProcessingRetryJob);

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 60)->get());

        UniqueUntilProcessingRetryJob::$handled = false;
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testRetryOfOwnerlessUniqueUntilProcessingJobDoesNotReleaseSubsequentLock(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        dispatch($job = new OwnerlessUniqueUntilProcessingRetryJob);

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 60)->get());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testLockIsReleasedOnModelNotFoundException(): void
    {
        UniqueTestSerializesModelsJob::$handled = false;

        /** @var User $user */
        $user = UserFactory::new()->create();
        $job = new UniqueTestSerializesModelsJob($user);

        $this->expectException(ModelNotFoundException::class);

        try {
            $user->delete();
            dispatch($job);
            $this->runQueueWorkerCommand(['--once' => true]);
            unserialize(serialize($job));
        } finally {
            $this->assertFalse($job::$handled);
            $this->assertModelMissing($user);
            $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
        }
    }

    public function testModelNotFoundExceptionDoesNotReleaseSubsequentLock(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        /** @var User $user */
        $user = UserFactory::new()->create();
        $job = new UniqueTestSerializesModelsJob($user);
        $cache = $this->app->get(Cache::class);
        $lock = new UniqueLock($cache);

        dispatch($job);

        $lock->release($job);

        $replacement = new UniqueTestSerializesModelsJob($user);
        $this->assertTrue($lock->acquire($replacement));

        $user->delete();
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertFalse($cache->lock($this->getLockKey($job), 10)->get());

        $lock->release($replacement);
    }

    public function testMissingModelInOrdinaryChildDoesNotReleaseParentUniqueLock(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        NestedUniqueParentJob::$dispatchedChild = false;

        /** @var User $user */
        $user = UserFactory::new()->create();

        dispatch($job = new NestedUniqueParentJob);

        $cache = $this->app->get(Cache::class);
        $this->assertFalse($cache->lock($this->getLockKey($job), 10)->get());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue(NestedUniqueParentJob::$dispatchedChild);
        $this->assertFalse($cache->lock($this->getLockKey($job), 10)->get());

        $user->delete();
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertSame(1, Queue::size());
        $this->assertFalse($cache->lock($this->getLockKey($job), 10)->get());
    }

    public function testQueueFakeReleasesUniqueJobLocksBetweenFakes(): void
    {
        Queue::fake();

        UniqueTestJob::dispatch();
        Queue::assertPushed(UniqueTestJob::class);

        Queue::fake();

        UniqueTestJob::dispatch();
        Queue::assertPushed(UniqueTestJob::class);
    }

    public function testQueueFakePreservesUniqueJobLockWithinTest(): void
    {
        Queue::fake();

        UniqueTestJob::dispatch();
        UniqueTestJob::dispatch();

        Queue::assertPushedTimes(UniqueTestJob::class, 1);
    }

    public function testRolledBackPushDoesNotReleaseAnotherDispatchesUniqueLock(): void
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        dispatch($job = new UniqueTestAfterCommitJob);

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        try {
            DB::transaction(function (): never {
                Queue::push(new UniqueTestAfterCommitJob);

                throw new Exception('Rollback.');
            });
        } catch (Exception) {
        }

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    /**
     * Get the unique lock key for the given job.
     */
    protected function getLockKey(object|string $job): string
    {
        return 'laravel_unique_job:' . (is_string($job) ? $job : get_class($job)) . ':';
    }

    public function testLockUsesDisplayNameWhenAvailable(): void
    {
        Bus::fake();

        $lockKey = 'laravel_unique_job:' . hash('xxh128', 'App\Actions\UniqueTestAction') . ':';

        dispatch(new UniqueTestJobWithDisplayName);
        $this->runQueueWorkerCommand(['--once' => true]);
        Bus::assertDispatched(UniqueTestJobWithDisplayName::class);

        $this->assertFalse(
            $this->app->get(Cache::class)->lock($lockKey, 10)->get()
        );

        Bus::assertDispatchedTimes(UniqueTestJobWithDisplayName::class);
        dispatch(new UniqueTestJobWithDisplayName);
        $this->runQueueWorkerCommand(['--once' => true]);
        Bus::assertDispatchedTimes(UniqueTestJobWithDisplayName::class);

        $this->assertFalse(
            $this->app->get(Cache::class)->lock($lockKey, 10)->get()
        );
    }

    public function testUniqueLockCreatesKeyWithClassName(): void
    {
        $this->assertSame(
            'laravel_unique_job:' . UniqueTestJob::class . ':',
            UniqueLock::getKey(new UniqueTestJob)
        );
    }

    public function testUniqueLockCreatesKeyWithIdAndClassName(): void
    {
        $this->assertSame(
            'laravel_unique_job:' . UniqueIdTestJob::class . ':unique-id-1',
            UniqueLock::getKey(new UniqueIdTestJob)
        );
    }

    public function testUniqueLockCreatesKeyWithDisplayNameWhenAvailable(): void
    {
        $this->assertSame(
            'laravel_unique_job:' . hash('xxh128', 'App\Actions\UniqueTestAction') . ':',
            UniqueLock::getKey(new UniqueTestJobWithDisplayName)
        );
    }

    public function testUniqueLockCreatesKeyWithIdAndDisplayNameWhenAvailable(): void
    {
        $this->assertSame(
            'laravel_unique_job:' . hash('xxh128', 'App\Actions\UniqueTestAction') . ':unique-id-2',
            UniqueLock::getKey(new UniqueIdTestJobWithDisplayName)
        );
    }
}

class UniqueTestJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue;
    use Queueable;
    use Dispatchable;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }
}

class UniqueTestFailJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue;
    use Queueable;
    use Dispatchable;

    public int $tries = 1;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;

        throw new Exception;
    }
}

class UniqueTestReleasedJob extends UniqueTestFailJob
{
    public int $tries = 1;

    public function handle(): void
    {
        static::$handled = true;

        $this->release();
    }
}

class UniqueTestRetryJob extends UniqueTestFailJob
{
    public int $tries = 2;
}

class UniqueUntilStartTestJob extends UniqueTestJob implements ShouldBeUniqueUntilProcessing
{
    public int $tries = 2;
}

class UniqueUntilProcessingRetryJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use InteractsWithQueue;
    use Queueable;
    use Dispatchable;

    public int $tries = 2;

    public static bool $handled = false;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$handled = true;

        if ($this->attempts() === 1) {
            throw new Exception('First attempt failure.');
        }
    }
}

class OwnerlessUniqueUntilProcessingRetryJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use InteractsWithQueue;
    use Dispatchable;

    public int $tries = 2;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        if ($this->attempts() === 1) {
            throw new Exception('First attempt failure.');
        }
    }
}

class UniqueTestSerializesModelsJob extends UniqueTestJob
{
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public User $user)
    {
    }
}

class UniqueViaJob extends UniqueTestJob
{
    public function uniqueVia(): Cache
    {
        return Container::getInstance()->make(Cache::class);
    }
}

class UniqueIdTestJob extends UniqueTestJob
{
    public function uniqueId(): string
    {
        return 'unique-id-1';
    }
}

class UniqueTestJobWithDisplayName extends UniqueTestJob
{
    public function displayName(): string
    {
        return 'App\Actions\UniqueTestAction';
    }
}

class UniqueIdTestJobWithDisplayName extends UniqueTestJob
{
    public function uniqueId(): string
    {
        return 'unique-id-2';
    }

    public function displayName(): string
    {
        return 'App\Actions\UniqueTestAction';
    }
}

class UniqueTestAfterCommitJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Create a job that dispatches after commit.
     */
    public function __construct()
    {
        $this->afterCommit = true;
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class NestedUniqueParentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public static bool $dispatchedChild = false;

    /**
     * Get the lifetime of the unique lock.
     */
    public function uniqueFor(): int
    {
        return 300;
    }

    /**
     * Dispatch a child while retaining this job's lock for a retry.
     */
    public function handle(): void
    {
        static::$dispatchedChild = true;

        NestedOrdinaryChildJob::dispatch(User::query()->firstOrFail());

        $this->release(120);
    }
}

class NestedOrdinaryChildJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    /**
     * Create a child job containing a model.
     */
    public function __construct(public User $user)
    {
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}
