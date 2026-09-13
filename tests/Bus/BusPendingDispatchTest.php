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
use Mockery as m;
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
    protected stdClass&m\MockInterface $job;

    protected PendingDispatchWithoutDestructor $pendingDispatch;

    /**
     * Set up the pending dispatch.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->job = m::mock(stdClass::class);
        $this->pendingDispatch = new PendingDispatchWithoutDestructor($this->job);
    }

    public function testOnConnection(): void
    {
        $this->job->expects('onConnection')->with('test-connection');
        $this->pendingDispatch->onConnection('test-connection');
    }

    public function testOnQueue(): void
    {
        $this->job->expects('onQueue')->with('test-queue');
        $this->pendingDispatch->onQueue('test-queue');
    }

    public function testConditionableCanConfigurePendingDispatch(): void
    {
        $this->job->expects('onQueue')->with('conditional-queue');

        $this->pendingDispatch->when(true, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->onQueue('conditional-queue'));
    }

    public function testWhenMethodOfConditionableTraitWithTrue(): void
    {
        $this->job->expects('delay')->with(300);

        $this->pendingDispatch->when(true, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));
    }

    public function testWhenMethodOfConditionableTraitWithFalse(): void
    {
        $this->job->shouldReceive('delay')->never();

        $this->pendingDispatch->when(false, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));
    }

    public function testUnlessMethodOfConditionableTraitWithTrue(): void
    {
        $this->job->shouldReceive('delay')->never();

        $this->pendingDispatch->unless(true, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));
    }

    public function testUnlessMethodOfConditionableTraitWithFalse(): void
    {
        $this->job->expects('delay')->with(300);

        $this->pendingDispatch->unless(false, fn (PendingDispatch $pendingDispatch): PendingDispatch => $pendingDispatch->delay(300));
    }

    public function testOnGroup(): void
    {
        $this->job->expects('onGroup')->with('test-group');
        $this->pendingDispatch->onGroup('test-group');
    }

    public function testOnGroupForwardsAnArray(): void
    {
        $groups = ['first', 'second'];

        $this->job->expects('onGroup')->with($groups);
        $this->pendingDispatch->onGroup($groups);
    }

    public function testWithDeduplicator(): void
    {
        $deduplicator = fn (): string => 'id';
        $this->job->expects('withDeduplicator')->with($deduplicator);
        $this->pendingDispatch->withDeduplicator($deduplicator);
    }

    public function testWithDeduplicatorForwardsAnArrayCallable(): void
    {
        $deduplicator = [$this, 'resolveDeduplicationId'];

        $this->job->expects('withDeduplicator')->with($deduplicator);
        $this->pendingDispatch->withDeduplicator($deduplicator);
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
        $this->job->expects('allOnConnection')->with('test-connection');
        $this->pendingDispatch->allOnConnection('test-connection');
    }

    public function testAllOnQueue(): void
    {
        $this->job->expects('allOnQueue')->with('test-queue');
        $this->pendingDispatch->allOnQueue('test-queue');
    }

    public function testDelay(): void
    {
        $this->job->expects('delay')->with(60);
        $this->pendingDispatch->delay(60);
    }

    public function testWithoutDelay(): void
    {
        $this->job->expects('withoutDelay');
        $this->pendingDispatch->withoutDelay();
    }

    public function testAfterCommit(): void
    {
        $this->job->expects('afterCommit');
        $this->pendingDispatch->afterCommit();
    }

    public function testBeforeCommit(): void
    {
        $this->job->expects('beforeCommit');
        $this->pendingDispatch->beforeCommit();
    }

    public function testChain(): void
    {
        $chain = [new stdClass];
        $this->job->expects('chain')->with($chain);
        $this->pendingDispatch->chain($chain);
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

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->never();
        $dispatcher->shouldReceive('dispatchAfterResponse')->never();
        $container->instance(Dispatcher::class, $dispatcher);

        $job = new PreparingDebouncedPendingDispatchJob(false);
        $pendingDispatch = new PendingDispatch($job);
        unset($pendingDispatch);

        $this->assertSame('', $job->debounceOwner);
    }

    public function testPrepareForDispatchAllowsDispatch(): void
    {
        Container::setInstance($container = new Container);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->expects('dispatch')->with(m::type(PreparingPendingDispatchJob::class));
        $dispatcher->shouldReceive('dispatchAfterResponse')->never();
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

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->never();
        $dispatcher->expects('dispatchAfterResponse')
            ->with($job)
            ->andReturnUsing(function (object $job) use (&$deferredJob, &$metadata): void {
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
        $newJob = m::mock(stdClass::class);
        $this->job->expects('appendToChain')->with($newJob);
        $this->pendingDispatch->appendToChain($newJob);
    }
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
