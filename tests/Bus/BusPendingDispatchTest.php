<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueJobPayloadContext;
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
    public function __destruct()
    {
        // Prevent the job from being dispatched
    }
}

class BusPendingDispatchTest extends TestCase
{
    protected $job;

    /**
     * @var PendingDispatchWithoutDestructor
     */
    protected $pendingDispatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->job = m::mock(stdClass::class);
        $this->pendingDispatch = new PendingDispatchWithoutDestructor($this->job);
    }

    public function testOnConnection(): void
    {
        $this->job->shouldReceive('onConnection')->once()->with('test-connection');
        $this->pendingDispatch->onConnection('test-connection');
    }

    public function testOnQueue(): void
    {
        $this->job->shouldReceive('onQueue')->once()->with('test-queue');
        $this->pendingDispatch->onQueue('test-queue');
    }

    public function testConditionableCanConfigurePendingDispatch(): void
    {
        $this->job->shouldReceive('onQueue')->once()->with('conditional-queue');

        $this->pendingDispatch->when(true, fn ($pendingDispatch) => $pendingDispatch->onQueue('conditional-queue'));
    }

    public function testWhenMethodOfConditionableTraitWithFalse(): void
    {
        $this->job->shouldReceive('delay')->never();

        $this->pendingDispatch->when(false, fn ($pendingDispatch) => $pendingDispatch->delay(300));
    }

    public function testUnlessMethodOfConditionableTraitWithTrue(): void
    {
        $this->job->shouldReceive('delay')->never();

        $this->pendingDispatch->unless(true, fn ($pendingDispatch) => $pendingDispatch->delay(300));
    }

    public function testUnlessMethodOfConditionableTraitWithFalse(): void
    {
        $this->job->shouldReceive('delay')->once()->with(300);

        $this->pendingDispatch->unless(false, fn ($pendingDispatch) => $pendingDispatch->delay(300));
    }

    public function testOnGroup(): void
    {
        $this->job->shouldReceive('onGroup')->once()->with('test-group');
        $this->pendingDispatch->onGroup('test-group');
    }

    public function testOnGroupForwardsAnArray(): void
    {
        $groups = ['first', 'second'];

        $this->job->shouldReceive('onGroup')->once()->with($groups);
        $this->pendingDispatch->onGroup($groups);
    }

    public function testWithDeduplicator(): void
    {
        $deduplicator = fn () => 'id';
        $this->job->shouldReceive('withDeduplicator')->once()->with($deduplicator);
        $this->pendingDispatch->withDeduplicator($deduplicator);
    }

    public function testWithDeduplicatorForwardsAnArrayCallable(): void
    {
        $deduplicator = [$this, 'resolveDeduplicationId'];

        $this->job->shouldReceive('withDeduplicator')->once()->with($deduplicator);
        $this->pendingDispatch->withDeduplicator($deduplicator);
    }

    public function resolveDeduplicationId(): string
    {
        return 'id';
    }

    public function testAllOnConnection(): void
    {
        $this->job->shouldReceive('allOnConnection')->once()->with('test-connection');
        $this->pendingDispatch->allOnConnection('test-connection');
    }

    public function testAllOnQueue(): void
    {
        $this->job->shouldReceive('allOnQueue')->once()->with('test-queue');
        $this->pendingDispatch->allOnQueue('test-queue');
    }

    public function testDelay(): void
    {
        $this->job->shouldReceive('delay')->once()->with(60);
        $this->pendingDispatch->delay(60);
    }

    public function testWithoutDelay(): void
    {
        $this->job->shouldReceive('withoutDelay')->once();
        $this->pendingDispatch->withoutDelay();
    }

    public function testAfterCommit(): void
    {
        $this->job->shouldReceive('afterCommit')->once();
        $this->pendingDispatch->afterCommit();
    }

    public function testBeforeCommit(): void
    {
        $this->job->shouldReceive('beforeCommit')->once();
        $this->pendingDispatch->beforeCommit();
    }

    public function testChain(): void
    {
        $chain = [new stdClass];
        $this->job->shouldReceive('chain')->once()->with($chain);
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

        try {
            $dispatcher = m::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')->never();
            $dispatcher->shouldReceive('dispatchAfterResponse')->never();
            $container->instance(Dispatcher::class, $dispatcher);

            $job = new PreparingDebouncedPendingDispatchJob(false);
            $pendingDispatch = new PendingDispatch($job);
            unset($pendingDispatch);

            $this->assertSame('', $job->debounceOwner);
        } finally {
            Container::setInstance(null);
        }
    }

    public function testPrepareForDispatchAllowsDispatch(): void
    {
        Container::setInstance($container = new Container);

        try {
            $dispatcher = m::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')->once()->with(m::type(PreparingPendingDispatchJob::class));
            $dispatcher->shouldReceive('dispatchAfterResponse')->never();
            $container->instance(Dispatcher::class, $dispatcher);

            $pendingDispatch = new PendingDispatch(new PreparingPendingDispatchJob(true));
            unset($pendingDispatch);
        } finally {
            Container::setInstance(null);
        }
    }

    public function testUniqueMetadataRemainsRegisteredUntilAfterResponsePayloadCreation(): void
    {
        Container::setInstance($container = new Container);

        try {
            $cache = new CacheRepository(new WorkerArrayStore, ['store' => 'unique']);
            $container->instance(CacheContract::class, $cache);

            $job = new UniquePendingDispatchJob($cache);
            $deferredJob = null;

            $dispatcher = m::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')->never();
            $dispatcher->shouldReceive('dispatchAfterResponse')
                ->once()
                ->with($job)
                ->andReturnUsing(function (object $job) use (&$deferredJob): void {
                    $deferredJob = $job;
                });
            $container->instance(Dispatcher::class, $dispatcher);

            $pendingDispatch = (new PendingDispatch($job))->afterResponse();
            unset($pendingDispatch);

            $this->assertSame($job, $deferredJob);
            $this->assertSame([
                'laravel_unique_job_cache_store' => 'unique',
                'laravel_unique_job_key' => 'laravel_unique_job:' . UniquePendingDispatchJob::class . ':after-response',
            ], UniqueJobPayloadContext::consume($deferredJob));
        } finally {
            Container::setInstance(null);
        }
    }

    public function testDynamicallyProxyMethods(): void
    {
        $newJob = m::mock(stdClass::class);
        $this->job->shouldReceive('appendToChain')->once()->with($newJob);
        $this->pendingDispatch->appendToChain($newJob);
    }
}

class PreparingPendingDispatchJob implements PreparesForDispatch
{
    public function __construct(
        protected bool $shouldDispatch
    ) {
    }

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
    public function __construct(
        protected CacheRepository $cache
    ) {
    }

    public function uniqueId(): string
    {
        return 'after-response';
    }

    public function uniqueVia(): CacheRepository
    {
        return $this->cache;
    }
}
