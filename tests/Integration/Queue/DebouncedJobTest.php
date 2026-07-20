<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\DebouncedJobTest;

use Carbon\CarbonImmutable;
use Exception;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Events\JobDebounced;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\Cache as CacheFacade;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use LogicException;

#[WithMigration]
#[WithMigration('cache')]
#[WithMigration('queue')]
class DebouncedJobTest extends QueueTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'database');
        $app['config']->set('queue.default', 'database');
        $this->driver = 'database';
    }

    public function testDebouncedJobDispatchesAndExecutes(): void
    {
        DebouncedTestJob::resetState();

        dispatch(new DebouncedTestJob('entity-1'));
        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue(DebouncedTestJob::$handled);
    }

    public function testSupersededDebouncedJobIsSkipped(): void
    {
        DebouncedTestJob::resetState();

        dispatch(new DebouncedTestJob('entity-1'));
        dispatch(new DebouncedTestJob('entity-1'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true], 2);

        $this->assertSame(1, DebouncedTestJob::$handleCount);
    }

    public function testTokenPersistsAfterSuccessfulExecution(): void
    {
        DebouncedTestJob::resetState();

        dispatch($job = new DebouncedTestJob('entity-1'));
        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue($job::$handled);
        $this->assertNotNull($this->app->get(Cache::class)->get(DebounceLock::getKey($job)));
    }

    public function testFailedDebouncedJobStillCallsHandler(): void
    {
        DebouncedTestFailJob::resetState();

        $this->expectException(Exception::class);

        try {
            dispatch_sync(new DebouncedTestFailJob('entity-1'));
        } finally {
            $this->assertTrue(DebouncedTestFailJob::$handled);
        }
    }

    public function testJobDebouncedEventFiresForSupersededJob(): void
    {
        $firedCount = 0;

        Event::listen(JobDebounced::class, function () use (&$firedCount): void {
            ++$firedCount;
        });

        dispatch(new DebouncedTestJob('entity-1'));
        dispatch(new DebouncedTestJob('entity-1'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true], 2);

        $this->assertSame(1, $firedCount);
    }

    public function testDebouncedAndUniqueThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('debounced job cannot also implement ShouldBeUnique');

        DebouncedAndUniqueTestJob::dispatch('entity-1');
    }

    public function testDebouncedAndUniqueDoesNotAcquireUniqueLockBeforeThrowing(): void
    {
        $job = new DebouncedAndUniqueTestJob('entity-1');

        try {
            $pending = dispatch($job);
            unset($pending);

            $this->fail('Expected a LogicException to be thrown.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('debounced job cannot also implement ShouldBeUnique', $exception->getMessage());
        }

        $cache = $this->app->get(Cache::class);
        $uniqueLock = $cache->lock(UniqueLock::getKey($job), 10);

        $this->assertTrue($uniqueLock->get());

        $uniqueLock->forceRelease();
    }

    public function testDebounceOwnerSurvivesSerialization(): void
    {
        $job = new DebouncedTestJob('entity-1');
        $job->debounceOwner = 'test-owner-token-123';

        $restored = unserialize(serialize($job));

        $this->assertSame('test-owner-token-123', $restored->debounceOwner);
    }

    public function testDifferentDebounceIdsDoNotInterfere(): void
    {
        DebouncedTestJob::resetState();

        dispatch(new DebouncedTestJob('entity-1'));
        dispatch(new DebouncedTestJob('entity-2'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true], 2);

        $this->assertSame(2, DebouncedTestJob::$handleCount);
    }

    public function testDebounceLockKeyFormat(): void
    {
        $key = DebounceLock::getKey(new DebouncedTestJob('entity-1'));

        $this->assertStringStartsWith('laravel_debounced_job:', $key);
        $this->assertStringEndsWith(':entity-1', $key);
    }

    public function testQueueFakeCapturesDebouncedJob(): void
    {
        Queue::fake();

        DebouncedTestJob::dispatch('entity-1');

        Queue::assertPushed(DebouncedTestJob::class);
    }

    public function testJobExecutesWhenCacheTokenIsEvicted(): void
    {
        DebouncedTestJob::resetState();

        dispatch($job = new DebouncedTestJob('entity-1'));

        $this->app->get(Cache::class)->forget(DebounceLock::getKey($job));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue(DebouncedTestJob::$handled);
    }

    public function testOwnerAwareReleaseDoesNotWipeNewerLock(): void
    {
        $cache = $this->app->get(Cache::class);
        $lock = new DebounceLock($cache);

        $jobA = new DebouncedTestJob('entity-1');
        $jobB = new DebouncedTestJob('entity-1');

        $ownerA = $lock->acquire($jobA)['owner'];
        $ownerB = $lock->acquire($jobB)['owner'];

        $lock->release($jobA, $ownerA);

        $this->assertSame($ownerB, $lock->getCurrentOwner($jobB));
    }

    public function testReleaseClearsMaxWaitTimestamp(): void
    {
        $cache = $this->app->get(Cache::class);
        $lock = new DebounceLock($cache);
        $job = new DebouncedWithMaxWaitJob('entity-1');

        $this->assertFalse($lock->acquire($job)['maxWaitExceeded']);

        $firstOwner = $cache->get(DebounceLock::getKey($job));

        $this->assertIsString($firstOwner);

        $lock->release($job, $firstOwner);

        $this->assertNull($cache->get(DebounceLock::getKey($job) . ':first_dispatched_at'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(61));

        $this->assertFalse($lock->acquire($job)['maxWaitExceeded']);
    }

    public function testSupersededDebouncedJobDoesNotDispatchChain(): void
    {
        DebouncedTestJob::resetState();
        ChainReceiverJob::resetState();

        dispatch(new DebouncedTestJob('entity-1'))->chain([new ChainReceiverJob]);
        dispatch(new DebouncedTestJob('entity-1'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true], 2);

        $this->assertSame(1, DebouncedTestJob::$handleCount);
        $this->assertFalse(ChainReceiverJob::$handled);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function testDebounceViaUsesCustomCacheStore(): void
    {
        DebouncedWithCustomCacheJob::resetState();

        dispatch(new DebouncedWithCustomCacheJob('entity-1'));

        $key = DebounceLock::getKey(new DebouncedWithCustomCacheJob('entity-1'));

        $this->assertNotNull(CacheFacade::store('array')->get($key));
        $this->assertNull(CacheFacade::store('database')->get($key));
    }

    public function testMaxDebounceWaitForcesImmediateExecution(): void
    {
        DebouncedWithMaxWaitJob::resetState();

        dispatch(new DebouncedWithMaxWaitJob('entity-1'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(50));
        dispatch(new DebouncedWithMaxWaitJob('entity-1'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(11));
        $job = new DebouncedWithMaxWaitJob('entity-1');
        $pending = dispatch($job);
        unset($pending);

        $this->assertSame(0, $job->delay);
    }

    public function testDebounceWithoutMaxWaitAllowsIndefiniteDelay(): void
    {
        $job1 = new DebouncedTestJob('entity-1');
        $pending = dispatch($job1);
        unset($pending);

        $this->assertSame(30, $job1->delay);

        $this->travelDebounceTo(CarbonImmutable::now()->addMinutes(10));
        $job2 = new DebouncedTestJob('entity-1');
        $pending = dispatch($job2);
        unset($pending);

        $this->assertSame(30, $job2->delay);
    }

    public function testDebounceLockReadsMaxWaitFromAttribute(): void
    {
        $lock = new DebounceLock($this->app->get(Cache::class));

        $this->assertSame(60, $lock->getMaxDebounceWait(new DebouncedWithMaxWaitJob('entity-1')));
    }

    public function testChildDebouncedJobInheritsFromParent(): void
    {
        ChildOfDebouncedTestJob::resetState();

        dispatch(new ChildOfDebouncedTestJob('entity-1'));
        dispatch(new ChildOfDebouncedTestJob('entity-1'));

        $this->travelDebounceTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true], 2);

        $this->assertSame(1, ChildOfDebouncedTestJob::$handleCount);
    }

    protected function travelDebounceTo(CarbonImmutable $date): void
    {
        $this->travelTo($date);
    }
}

#[DebounceFor(30)]
class DebouncedTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public static int $handleCount = 0;

    public function __construct(public string $entityId)
    {
    }

    public static function resetState(): void
    {
        static::$handled = false;
        static::$handleCount = 0;
    }

    public function debounceId(): string
    {
        return $this->entityId;
    }

    public function handle(): void
    {
        static::$handled = true;
        ++static::$handleCount;
    }
}

#[DebounceFor(30)]
class DebouncedTestFailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public static bool $handled = false;

    public function __construct(public string $entityId)
    {
    }

    public static function resetState(): void
    {
        static::$handled = false;
    }

    public function debounceId(): string
    {
        return $this->entityId;
    }

    public function handle(): void
    {
        static::$handled = true;

        throw new Exception;
    }
}

#[DebounceFor(30)]
class DebouncedAndUniqueTestJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $entityId)
    {
    }

    public function debounceId(): string
    {
        return $this->entityId;
    }

    public function handle(): void
    {
    }
}

class ChainReceiverJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public static function resetState(): void
    {
        static::$handled = false;
    }

    public function handle(): void
    {
        static::$handled = true;
    }
}

#[DebounceFor(30)]
class DebouncedWithCustomCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public function __construct(public string $entityId)
    {
    }

    public static function resetState(): void
    {
        static::$handled = false;
    }

    public function debounceId(): string
    {
        return $this->entityId;
    }

    public function debounceVia(): Cache
    {
        return Container::getInstance()
            ->make(CacheFactory::class)
            ->store('array');
    }

    public function handle(): void
    {
        static::$handled = true;
    }
}

#[DebounceFor(30, maxWait: 60)]
class DebouncedWithMaxWaitJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static int $handleCount = 0;

    public function __construct(public string $entityId)
    {
    }

    public static function resetState(): void
    {
        static::$handleCount = 0;
    }

    public function debounceId(): string
    {
        return $this->entityId;
    }

    public function handle(): void
    {
        ++static::$handleCount;
    }
}

class ChildOfDebouncedTestJob extends DebouncedTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static int $handleCount = 0;

    public function __construct(public string $entityId)
    {
    }

    public static function resetState(): void
    {
        static::$handleCount = 0;
    }

    public function debounceId(): string
    {
        return $this->entityId;
    }

    public function handle(): void
    {
        ++static::$handleCount;
    }
}
