<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\UniqueUntilProcessingJobTest;

use Hypervel\Bus\Queueable;
use Hypervel\Cache\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

#[WithMigration]
#[WithMigration('cache')]
#[WithMigration('queue')]
class UniqueUntilProcessingJobTest extends QueueTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('queue.default', 'database');
        $config->set('cache.default', 'database');
    }

    public function testShouldBeUniqueUntilProcessingReleasesLockWhenJobIsReleasedByAMiddleware(): void
    {
        // Job that does not release and gets processed
        UniqueTestJobThatDoesNotRelease::dispatch();
        $lockKey = DB::table('cache_locks')->orderBy('id')->first()->key;
        $this->assertNotNull($lockKey);
        $this->runQueueWorkerCommand(['--once' => true]);
        $this->assertFalse(UniqueTestJobThatDoesNotRelease::$released);
        $lockKey = DB::table('cache_locks')->first()->key ?? null;
        $this->assertNull($lockKey);
        $this->assertDatabaseCount('jobs', 0);

        // Job that releases and does not get processed
        UniqueUntilProcessingJobThatReleases::dispatch();
        $lockKey = DB::table('cache_locks')->first()->key;
        $this->assertNotNull($lockKey);
        $this->runQueueWorkerCommand(['--once' => true]);
        $this->assertFalse(UniqueUntilProcessingJobThatReleases::$handled);
        $this->assertTrue(UniqueUntilProcessingJobThatReleases::$released);
        $lockKey = DB::table('cache_locks')->orderBy('id')->first()->key ?? null;
        $this->assertNotNull($lockKey);

        UniqueUntilProcessingJobThatReleases::dispatch();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function testShouldBeUniqueUntilProcessingReleasesLockWhenLaterAttemptIsProcessed(): void
    {
        UniqueUntilProcessingJobThatReleasesOnce::dispatch();

        $this->assertNotNull(DB::table('cache_locks')->first());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertFalse(UniqueUntilProcessingJobThatReleasesOnce::$handled);
        $this->assertNotNull(DB::table('cache_locks')->first());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue(UniqueUntilProcessingJobThatReleasesOnce::$handled);
        $this->assertNull(DB::table('cache_locks')->first());
    }

    #[DataProvider('ownerlessCacheRepositories')]
    public function testJobWithoutQueueableReleasesItsLockWhenLaterAttemptIsProcessed(bool $customCache): void
    {
        UniqueUntilProcessingJobWithoutQueueable::dispatch($customCache);

        $this->assertNotNull(DB::table('cache_locks')->first());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertFalse(UniqueUntilProcessingJobWithoutQueueable::$handled);
        $this->assertNotNull(DB::table('cache_locks')->first());

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue(UniqueUntilProcessingJobWithoutQueueable::$handled);
        $this->assertNull(DB::table('cache_locks')->first());
    }

    /**
     * Provide cache repositories for jobs without Queueable state.
     */
    public static function ownerlessCacheRepositories(): array
    {
        return [
            'default repository' => [false],
            'unnamed repository' => [true],
        ];
    }
}

class UniqueTestJobThatDoesNotRelease implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use InteractsWithQueue;
    use Queueable;
    use Dispatchable;

    public static bool $handled = false;

    public static bool $released = false;

    public function __construct()
    {
        static::$handled = false;
        static::$released = false;
    }

    public function handle(): void
    {
        static::$handled = true;
    }
}

class UniqueUntilProcessingJobThatReleases extends UniqueTestJobThatDoesNotRelease
{
    public function middleware(): array
    {
        return [
            function (self $job): mixed {
                static::$released = true;

                return $job->release(30);
            },
        ];
    }

    public function uniqueId(): int
    {
        return 100;
    }
}

class UniqueUntilProcessingJobThatReleasesOnce extends UniqueTestJobThatDoesNotRelease
{
    public int $tries = 2;

    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [
            function (self $job, callable $next): mixed {
                if ($job->attempts() === 1) {
                    return $job->release();
                }

                return $next($job);
            },
        ];
    }

    /**
     * Get the unique identifier for the job.
     */
    public function uniqueId(): int
    {
        return 200;
    }
}

class UniqueUntilProcessingJobWithoutQueueable implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;

    public int $tries = 2;

    public static bool $handled = false;

    /**
     * Create a job with the selected cache repository.
     */
    public function __construct(public bool $customCache)
    {
        static::$handled = false;
    }

    /**
     * Resolve an unnamed repository when requested.
     */
    public function uniqueVia(): ?Cache
    {
        return $this->customCache ? new Repository(Container::getInstance()->make(Cache::class)->getStore()) : null;
    }

    /**
     * Release the first attempt before invoking the handler.
     */
    public function middleware(): array
    {
        return [
            function (self $job, callable $next): mixed {
                if ($job->attempts() === 1) {
                    return $job->release();
                }

                return $next($job);
            },
        ];
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$handled = true;
    }
}
