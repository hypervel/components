<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\Queueable;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Queue\PreparesForDispatch;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use ReflectionClass;
use stdClass;

class PendingDispatchWithoutDestructor extends PendingDispatch
{
    /**
     * Prevent dispatch while testing the configuration methods.
     */
    public function __destruct()
    {
        // Prevent the job from being dispatched
    }
}

class BusPendingDispatchTest extends TestCase
{
    use VerifiesDoubles;

    protected PendingDispatchTestJob $job;

    protected PendingDispatchWithoutDestructor $pendingDispatch;

    /**
     * Set up the pending dispatch.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->job = new PendingDispatchTestJob;
        $this->pendingDispatch = new PendingDispatchWithoutDestructor($this->job);
    }

    public function testOnConnection(): void
    {
        $this->pendingDispatch->onConnection('test-connection');

        $this->assertSame('test-connection', $this->job->connection);
    }

    public function testOnQueue(): void
    {
        $this->pendingDispatch->onQueue('test-queue');

        $this->assertSame('test-queue', $this->job->queue);
    }

    public function testOnGroup(): void
    {
        $this->pendingDispatch->onGroup('test-group');

        $this->assertSame('test-group', $this->job->messageGroup);
    }

    public function testOnGroupForwardsAnArray(): void
    {
        $groups = ['first', 'second'];

        $this->pendingDispatch->onGroup($groups);

        $this->assertSame($groups, $this->job->messageGroup);
    }

    public function testWithDeduplicator(): void
    {
        $deduplicator = fn (): string => 'id';
        $this->pendingDispatch->withDeduplicator($deduplicator);

        $this->assertSame($deduplicator, $this->job->deduplicator->getClosure());
    }

    public function testWithDeduplicatorForwardsAnArrayCallable(): void
    {
        $deduplicator = [$this, 'resolveDeduplicationId'];

        $this->pendingDispatch->withDeduplicator($deduplicator);

        $this->assertSame($deduplicator, $this->job->deduplicator);
    }

    /**
     * Resolve the message deduplication ID.
     */
    public function resolveDeduplicationId(): string
    {
        return 'id';
    }

    public function testAllOnConnection(): void
    {
        $this->pendingDispatch->allOnConnection('test-connection');

        $this->assertSame('test-connection', $this->job->connection);
        $this->assertSame('test-connection', $this->job->chainConnection);
    }

    public function testAllOnQueue(): void
    {
        $this->pendingDispatch->allOnQueue('test-queue');

        $this->assertSame('test-queue', $this->job->queue);
        $this->assertSame('test-queue', $this->job->chainQueue);
    }

    public function testDelay(): void
    {
        $this->pendingDispatch->delay(60);

        $this->assertSame(60, $this->job->delay);
    }

    public function testWithoutDelay(): void
    {
        $this->pendingDispatch->delay(60)->withoutDelay();

        $this->assertSame(0, $this->job->delay);
    }

    public function testAfterCommit(): void
    {
        $this->pendingDispatch->afterCommit();

        $this->assertTrue($this->job->afterCommit);
    }

    public function testBeforeCommit(): void
    {
        $this->pendingDispatch->beforeCommit();

        $this->assertFalse($this->job->afterCommit);
    }

    public function testChain(): void
    {
        $this->pendingDispatch->chain([new stdClass]);

        $this->assertSame([serialize(new stdClass)], $this->job->chained);
    }

    public function testAfterResponse(): void
    {
        $this->pendingDispatch->afterResponse();
        $this->assertTrue(
            (new ReflectionClass($this->pendingDispatch))->getProperty('afterResponse')->getValue($this->pendingDispatch)
        );
    }

    public function testAfterResponseCanBeDisabled(): void
    {
        $this->pendingDispatch->afterResponse()->afterResponse(false);

        $this->assertFalse(
            (new ReflectionClass($this->pendingDispatch))->getProperty('afterResponse')->getValue($this->pendingDispatch)
        );
    }

    public function testGetJob(): void
    {
        $this->assertSame($this->job, $this->pendingDispatch->getJob());
    }

    public function testPrepareForDispatchCanAbortDispatchBeforeDebounceCacheIsResolved(): void
    {
        Container::setInstance($container = new Container);

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('dispatch')->never();
        $dispatcher->expects('dispatchAfterResponse')->never();
        $container->instance(Dispatcher::class, $dispatcher);

        $job = new PreparingDebouncedPendingDispatchJob(false);
        $pendingDispatch = new PendingDispatch($job);
        unset($pendingDispatch);

        $this->assertSame('', $job->debounceOwner);
    }

    public function testPrepareForDispatchCanAbortDispatchBeforeUniqueLockCacheIsResolved(): void
    {
        Container::setInstance($container = new Container);

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('dispatch')->never();
        $dispatcher->expects('dispatchAfterResponse')->never();
        $container->instance(Dispatcher::class, $dispatcher);

        $pendingDispatch = new PendingDispatch(new PreparingUniquePendingDispatchJob(false));
        unset($pendingDispatch);
    }

    public function testPrepareForDispatchAllowsDispatch(): void
    {
        Container::setInstance($container = new Container);

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('dispatch')->with(Argument::type(PreparingPendingDispatchJob::class));
        $dispatcher->expects('dispatchAfterResponse')->never();
        $container->instance(Dispatcher::class, $dispatcher);

        $pendingDispatch = new PendingDispatch(new PreparingPendingDispatchJob(true));
        unset($pendingDispatch);
    }

    public function testUniqueMetadataReachesAfterResponseDispatcherBeforeUnclaimedOwnershipIsReleased(): void
    {
        Container::setInstance($container = new Container);

        $cache = new CacheRepository(new WorkerArrayStore, ['store' => 'unique']);
        $container->instance(CacheContract::class, $cache);

        $job = new UniquePendingDispatchJob($cache);
        $deferredJob = null;
        $metadata = null;

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('dispatch')->never();
        $dispatcher->expects('dispatchAfterResponse')
            ->with(Argument::same($job))
            ->resolves(function (object $job) use (&$deferredJob, &$metadata): void {
                $deferredJob = $job;
                $metadata = DispatchLockContext::peekPayloadMetadata($job);
            });
        $container->instance(Dispatcher::class, $dispatcher);

        $pendingDispatch = (new PendingDispatch($job))->afterResponse();
        unset($pendingDispatch);

        $this->assertSame($job, $deferredJob);
        $this->assertNotNull($metadata);
        $this->assertSame('unique', $metadata['laravel_unique_job_cache_store']);
        $this->assertSame(
            'laravel_unique_job:' . UniquePendingDispatchJob::class . ':after-response',
            $metadata['laravel_unique_job_key'],
        );
        $this->assertNotSame('', $metadata['laravel_unique_job_lock_owner']);
        $this->assertNull(DispatchLockContext::peekPayloadMetadata($job));
        $this->assertFalse(
            $cache->restoreLock($metadata['laravel_unique_job_key'], $metadata['laravel_unique_job_lock_owner'])->isLocked()
        );
    }

    public function testDynamicallyProxyMethods(): void
    {
        $this->pendingDispatch->appendToChain(new stdClass);

        $this->assertSame([serialize(new stdClass)], $this->job->chained);
    }

    public function testWhenMethodOfConditionableTraitWithTrue(): void
    {
        $this->pendingDispatch->when(true, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));

        $this->assertSame(300, $this->job->delay);
    }

    public function testWhenMethodOfConditionableTraitWithFalse(): void
    {
        $this->pendingDispatch->when(false, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));

        $this->assertNull($this->job->delay);
    }

    public function testUnlessMethodOfConditionableTraitWithTrue(): void
    {
        $this->pendingDispatch->unless(true, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));

        $this->assertNull($this->job->delay);
    }

    public function testUnlessMethodOfConditionableTraitWithFalse(): void
    {
        $this->pendingDispatch->unless(false, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));

        $this->assertSame(300, $this->job->delay);
    }
}

class PendingDispatchTestJob
{
    use Queueable;
}

class PreparingPendingDispatchJob implements PreparesForDispatch
{
    /**
     * Create a job with the given dispatch decision.
     */
    public function __construct(
        protected bool $shouldDispatch
    ) {
    }

    /**
     * Determine whether the job should be dispatched.
     */
    public function prepareForDispatch(): bool
    {
        return $this->shouldDispatch;
    }
}

#[DebounceFor(30)]
class PreparingDebouncedPendingDispatchJob extends PreparingPendingDispatchJob
{
    use Queueable;
}

class PreparingUniquePendingDispatchJob extends PreparingPendingDispatchJob implements ShouldBeUnique
{
}

class UniquePendingDispatchJob implements ShouldBeUnique
{
    /**
     * Create a job with its unique-lock cache.
     */
    public function __construct(
        protected CacheRepository $cache
    ) {
    }

    /**
     * Get the job's unique ID.
     */
    public function uniqueId(): string
    {
        return 'after-response';
    }

    /**
     * Get the cache for the job's unique lock.
     */
    public function uniqueVia(): CacheRepository
    {
        return $this->cache;
    }
}
