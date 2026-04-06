<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\UniqueJobTest;

use Exception;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Tests\Integration\Queue\QueueTestCase;

#[WithMigration]
#[WithMigration('cache')]
#[WithMigration('queue')]
/**
 * @internal
 * @coversNothing
 */
class UniqueJobTest extends QueueTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'database');
        $app['config']->set('queue.default', 'database');
        $this->driver = 'database';
    }

    public function testUniqueJobsAreNotDispatched()
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

    public function testUniqueJobWithViaDispatched()
    {
        Bus::fake();

        UniqueViaJob::dispatch();
        Bus::assertDispatched(UniqueViaJob::class);
    }

    public function testLockIsReleasedForSuccessfulJobs()
    {
        UniqueTestJob::$handled = false;
        dispatch($job = new UniqueTestJob);
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testLockIsReleasedForFailedJobs()
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

    public function testLockIsNotReleasedForJobRetries()
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

    public function testLockIsNotReleasedForJobReleases()
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

    public function testLockCanBeReleasedBeforeProcessing()
    {
        $this->markTestSkippedWhenUsingSyncQueueDriver();

        UniqueUntilStartTestJob::$handled = false;

        dispatch($job = new UniqueUntilStartTestJob);

        $this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($job), 10)->get());
    }

    public function testLockIsReleasedOnModelNotFoundException()
    {
        UniqueTestSerializesModelsJob::$handled = false;

        /** @var \Illuminate\Foundation\Auth\User */
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

    public function testQueueFakeReleasesUniqueJobLocksBetweenFakes()
    {
        Queue::fake();

        UniqueTestJob::dispatch();
        Queue::assertPushed(UniqueTestJob::class);

        Queue::fake();

        UniqueTestJob::dispatch();
        Queue::assertPushed(UniqueTestJob::class);
    }

    public function testQueueFakePreservesUniqueJobLockWithinTest()
    {
        Queue::fake();

        UniqueTestJob::dispatch();
        UniqueTestJob::dispatch();

        Queue::assertPushedTimes(UniqueTestJob::class, 1);
    }

    protected function getLockKey($job)
    {
        return 'laravel_unique_job:' . (is_string($job) ? $job : get_class($job)) . ':';
    }

    public function testLockUsesDisplayNameWhenAvailable()
    {
        Bus::fake();

        $lockKey = 'laravel_unique_job:App\Actions\UniqueTestAction:';

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

    public function testUniqueLockCreatesKeyWithClassName()
    {
        $this->assertEquals(
            'laravel_unique_job:' . UniqueTestJob::class . ':',
            UniqueLock::getKey(new UniqueTestJob)
        );
    }

    public function testUniqueLockCreatesKeyWithIdAndClassName()
    {
        $this->assertEquals(
            'laravel_unique_job:' . UniqueIdTestJob::class . ':unique-id-1',
            UniqueLock::getKey(new UniqueIdTestJob)
        );
    }

    public function testUniqueLockCreatesKeyWithDisplayNameWhenAvailable()
    {
        $this->assertEquals(
            'laravel_unique_job:App\Actions\UniqueTestAction:unique-id-2',
            UniqueLock::getKey(new UniqueIdTestJobWithDisplayName)
        );
    }

    public function testUniqueLockCreatesKeyWithIdAndDisplayNameWhenAvailable()
    {
        $this->assertEquals(
            'laravel_unique_job:App\Actions\UniqueTestAction:unique-id-2',
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
