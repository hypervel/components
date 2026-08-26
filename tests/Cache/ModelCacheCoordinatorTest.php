<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\CarbonInterval;
use Closure;
use Hypervel\Cache\ArrayLock;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Lock;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Engine\Channel;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class ModelCacheCoordinatorTest extends TestCase
{
    public function testCachedPositiveAndNullValuesReturnWithoutLockingOrReading(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $repository = new Repository(new ArrayStore);
        $reads = 0;

        $this->assertSame('user', $coordinator->fill(
            $repository,
            'positive',
            300,
            function () use (&$reads): string {
                ++$reads;

                return 'user';
            },
        ));
        $this->assertNull($coordinator->fill(
            $repository,
            'null',
            300,
            function () use (&$reads): null {
                ++$reads;

                return null;
            },
        ));

        $this->assertSame('user', $coordinator->fill(
            $repository,
            'positive',
            300,
            fn () => throw new RuntimeException('The source must not be read on a cache hit.'),
        ));
        $this->assertNull($coordinator->fill(
            $repository,
            'null',
            300,
            fn () => throw new RuntimeException('The source must not be read on a cached-null hit.'),
        ));
        $this->assertSame(2, $reads);
    }

    public function testColdFillDoubleChecksAfterLockAcquisition(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $seedRepository = new Repository(new ArrayStore);
        $coordinator->fill($seedRepository, 'seed', 300, fn (): string => 'published');
        $envelope = $seedRepository->getStore()->get('seed');

        $repository = m::mock(CacheRepository::class);
        $store = m::mock(Store::class, LockProvider::class);
        $lock = m::mock(LockContract::class);

        $repository->shouldReceive('get')->twice()->with('key')->andReturn(null, $envelope);
        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $repository->shouldNotReceive('put');
        $store->shouldReceive('lock')->once()->with(m::type('string'), 10)->andReturn($lock);
        $lock->shouldReceive('get')->once()->with(m::type(Closure::class))->andReturnUsing(
            static fn (Closure $callback): mixed => $callback(),
        );

        $this->assertSame('published', $coordinator->fill(
            $repository,
            'key',
            300,
            fn () => throw new RuntimeException('The source must not be read after a warm double-check.'),
        ));
    }

    public function testLazyWriterIsResolvedOnlyWhenPublishing(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $plainRepository = new Repository(new ArrayStore);
        $writerRepository = new Repository(new ArrayStore);
        $writerResolutions = 0;

        $this->assertSame('user', $coordinator->fill(
            $plainRepository,
            'user',
            300,
            fn (): string => 'user',
            writeCache: function () use (&$writerResolutions, $writerRepository): CacheRepository {
                ++$writerResolutions;

                return $writerRepository;
            },
        ));

        $this->assertSame(1, $writerResolutions);
        $this->assertNull($plainRepository->getStore()->get('user'));
        $this->assertNotNull($writerRepository->getStore()->get('user'));

        $this->assertSame('user', $coordinator->fill(
            $writerRepository,
            'user',
            300,
            fn () => throw new RuntimeException('The source must not be read on a cache hit.'),
            writeCache: function () use (&$writerResolutions, $writerRepository): CacheRepository {
                ++$writerResolutions;

                return $writerRepository;
            },
        ));

        $this->assertSame(1, $writerResolutions);
    }

    public function testNullIsNotPublishedWhenNullCachingIsDisabled(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $repository = new Repository(new ArrayStore);
        $reads = 0;

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertNull($coordinator->fill(
                $repository,
                'missing',
                300,
                function () use (&$reads): null {
                    ++$reads;

                    return null;
                },
                cacheNull: false,
            ));
        }

        $this->assertSame(2, $reads);
        $this->assertNull($repository->getStore()->get('missing'));
    }

    public function testFailedLockAcquisitionReadsWithoutPublishingOrResolvingWriter(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $repository = m::mock(CacheRepository::class);
        $store = m::mock(Store::class, LockProvider::class);
        $lock = m::mock(LockContract::class);
        $writerResolutions = 0;

        $repository->shouldReceive('get')->once()->with('key')->andReturnNull();
        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $repository->shouldNotReceive('put');
        $store->shouldReceive('lock')->once()->with(m::type('string'), 10)->andReturn($lock);
        $lock->shouldReceive('get')->once()->with(m::type(Closure::class))->andReturnFalse();

        $this->assertSame('database', $coordinator->fill(
            $repository,
            'key',
            300,
            fn (): string => 'database',
            writeCache: function () use (&$writerResolutions, $repository): CacheRepository {
                ++$writerResolutions;

                return $repository;
            },
        ));
        $this->assertSame(0, $writerResolutions);
    }

    public function testFalseCallbackResultIsNotMistakenForFailedAcquisition(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $repository = m::mock(CacheRepository::class);
        $store = m::mock(Store::class, LockProvider::class);
        $lock = m::mock(LockContract::class);

        $repository->shouldReceive('get')->twice()->with('key')->andReturnNull();
        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $repository->shouldReceive('put')->once()->with(
            'key',
            m::on(static fn (mixed $value): bool => is_array($value)),
            300,
        )->andReturnTrue();
        $store->shouldReceive('lock')->once()->with(m::type('string'), 10)->andReturn($lock);
        $lock->shouldReceive('get')->once()->with(m::type(Closure::class))->andReturnUsing(
            static fn (Closure $callback): mixed => $callback(),
        );

        $reads = 0;
        $this->assertFalse($coordinator->fill(
            $repository,
            'key',
            300,
            function () use (&$reads): false {
                ++$reads;

                return false;
            },
        ));
        $this->assertSame(1, $reads);
    }

    public function testFillPropagatesSourceFailureAndReleasesTheLock(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $repository = m::mock(CacheRepository::class);
        $store = m::mock(Store::class, LockProvider::class);
        $lock = new CoordinatorTestLock;

        $repository->shouldReceive('get')->twice()->with('key')->andReturnNull();
        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $repository->shouldNotReceive('put');
        $store->shouldReceive('lock')->once()->with(m::type('string'), 10)->andReturn($lock);

        try {
            $coordinator->fill(
                $repository,
                'key',
                300,
                fn () => throw new RuntimeException('source failure'),
            );

            $this->fail('Expected the source failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source failure', $exception->getMessage());
        }

        $this->assertTrue($lock->released);
    }

    public function testInvalidationUsesTheSameBoundedLockAndForgetsInsideIt(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $repository = m::mock(CacheRepository::class);
        $store = m::mock(Store::class, LockProvider::class);
        $fillLock = m::mock(LockContract::class);
        $invalidateLock = m::mock(LockContract::class);
        $lockNames = [];

        $repository->shouldReceive('get')->once()->with('a-very-long-data-key')->andReturnNull();
        $repository->shouldReceive('getStore')->twice()->andReturn($store);
        $repository->shouldReceive('forget')->once()->with('a-very-long-data-key')->andReturnTrue();
        $store->shouldReceive('lock')->twice()->andReturnUsing(
            function (string $name, int $seconds) use (&$lockNames, $fillLock, $invalidateLock): LockContract {
                $lockNames[] = [$name, $seconds];

                return count($lockNames) === 1 ? $fillLock : $invalidateLock;
            },
        );
        $fillLock->shouldReceive('get')->once()->with(m::type(Closure::class))->andReturnFalse();
        $invalidateLock->shouldReceive('betweenBlockedAttemptsSleepFor')->once()->with(25)->andReturnSelf();
        $invalidateLock->shouldReceive('block')->once()->with(11, m::type(Closure::class))->andReturnUsing(
            static fn (int $seconds, Closure $callback): mixed => $callback(),
        );

        $this->assertSame('uncached', $coordinator->fill(
            $repository,
            'a-very-long-data-key',
            300,
            fn (): string => 'uncached',
        ));
        $coordinator->invalidate($repository, 'a-very-long-data-key');

        $this->assertSame($lockNames[0][0], $lockNames[1][0]);
        $this->assertSame(10, $lockNames[0][1]);
        $this->assertSame(10, $lockNames[1][1]);
        $this->assertNotSame('a-very-long-data-key', $lockNames[0][0]);
        $this->assertLessThanOrEqual(64, strlen($lockNames[0][0]));
    }

    public function testFillPublicationAndInvalidationAreOrderedUnderTheSameLock(): void
    {
        $sourceRead = new Channel(1);
        $contention = new Channel(1);
        $store = new ContendedCoordinatorWorkerArrayStore($contention);
        $repository = new Repository($store);
        $coordinator = new ModelCacheCoordinator;

        parallel([
            'fill' => function () use ($contention, $coordinator, $repository, $sourceRead): void {
                $coordinator->fill(
                    $repository,
                    'user:1',
                    300,
                    static function () use ($contention, $sourceRead): string {
                        $sourceRead->push(true);

                        if ($contention->pop(1) !== true) {
                            throw new RuntimeException('The invalidation did not contend with the fill lock.');
                        }

                        return 'user';
                    },
                );
            },
            'invalidate' => function () use ($coordinator, $repository, $sourceRead): void {
                if ($sourceRead->pop(1) !== true) {
                    throw new RuntimeException('The fill did not reach its source read.');
                }

                $coordinator->invalidate($repository, 'user:1');
            },
        ]);

        $this->assertSame(['put', 'forget'], $store->operations);
        $this->assertGreaterThan(0, $store->failedAcquisitions);
        $this->assertNull($repository->get('user:1'));
    }

    public function testInvalidationRetriesBriefLockCollisionAfterTwentyFiveMilliseconds(): void
    {
        Sleep::fake();

        $coordinator = new ModelCacheCoordinator;
        $repository = m::mock(CacheRepository::class);
        $store = m::mock(Store::class, LockProvider::class);
        $lock = new CoordinatorTestLock(failFirstAcquisition: true);

        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $repository->shouldReceive('forget')->once()->with('key')->andReturnTrue();
        $store->shouldReceive('lock')->once()->with(m::type('string'), 10)->andReturn($lock);

        $coordinator->invalidate($repository, 'key');

        Sleep::assertSlept(
            fn (CarbonInterval $duration): bool => (float) $duration->totalMilliseconds === 25.0,
        );
        Sleep::assertSleptTimes(1);
        $this->assertSame(2, $lock->acquisitionAttempts);
        $this->assertTrue($lock->released);
    }

    public function testInvalidationPropagatesLockTimeoutWithoutForgetting(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $repository = m::mock(CacheRepository::class);
        $store = m::mock(Store::class, LockProvider::class);
        $lock = m::mock(LockContract::class);

        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $repository->shouldNotReceive('forget');
        $store->shouldReceive('lock')->once()->with(m::type('string'), 10)->andReturn($lock);
        $lock->shouldReceive('betweenBlockedAttemptsSleepFor')->once()->with(25)->andReturnSelf();
        $lock->shouldReceive('block')->once()->with(11, m::type(Closure::class))->andThrow(new LockTimeoutException);

        $this->expectException(LockTimeoutException::class);

        $coordinator->invalidate($repository, 'key');
    }

    public function testUnsupportedStoreIsRejectedOnlyAfterAMiss(): void
    {
        $coordinator = new ModelCacheCoordinator;
        $seedRepository = new Repository(new ArrayStore);
        $coordinator->fill($seedRepository, 'seed', 300, fn (): string => 'cached');
        $envelope = $seedRepository->getStore()->get('seed');

        $hitRepository = m::mock(CacheRepository::class);
        $hitRepository->shouldReceive('get')->once()->with('key')->andReturn($envelope);
        $hitRepository->shouldNotReceive('getStore');

        $this->assertSame('cached', $coordinator->fill(
            $hitRepository,
            'key',
            300,
            fn () => throw new RuntimeException('The source must not be read on a cache hit.'),
        ));

        $missRepository = m::mock(CacheRepository::class);
        $unsupportedStore = m::mock(Store::class);
        $missRepository->shouldReceive('get')->once()->with('key')->andReturnNull();
        $missRepository->shouldReceive('getStore')->once()->andReturn($unsupportedStore);

        $this->expectExceptionMessage('does not provide atomic locks');

        $coordinator->fill($missRepository, 'key', 300, fn (): string => 'database');
    }
}

class CoordinatorTestLock extends Lock
{
    public int $acquisitionAttempts = 0;

    public bool $released = false;

    public function __construct(private readonly bool $failFirstAcquisition = false)
    {
        parent::__construct('model-cache', 10, 'owner');
    }

    public function acquire(): bool
    {
        ++$this->acquisitionAttempts;

        return ! $this->failFirstAcquisition || $this->acquisitionAttempts > 1;
    }

    public function release(): bool
    {
        $this->released = true;

        return true;
    }

    public function forceRelease(): void
    {
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->owner;
    }
}

class ContendedCoordinatorWorkerArrayStore extends WorkerArrayStore
{
    public int $failedAcquisitions = 0;

    /** @var list<string> */
    public array $operations = [];

    public function __construct(private readonly Channel $contention)
    {
        parent::__construct();
    }

    /**
     * Get a lock instance that reports failed acquisition attempts.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): ArrayLock
    {
        return new ContendedCoordinatorArrayLock($this, $name, $seconds, $owner);
    }

    /**
     * Record a failed acquisition attempt.
     */
    public function recordFailedAcquisition(): void
    {
        if (++$this->failedAcquisitions === 1) {
            $this->contention->push(true);
        }
    }

    protected function putCacheItem(string $key, array $item): void
    {
        $operation = $this->getLockRecords() === [] ? 'put-unlocked' : 'put';

        parent::putCacheItem($key, $item);

        $this->operations[] = $operation;
    }

    protected function forgetCacheItem(string $key): bool
    {
        $forgotten = parent::forgetCacheItem($key);

        $this->operations[] = 'forget';

        return $forgotten;
    }
}

class ContendedCoordinatorArrayLock extends ArrayLock
{
    public function __construct(
        private readonly ContendedCoordinatorWorkerArrayStore $instrumentedStore,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($instrumentedStore, $name, $seconds, $owner);
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        $acquired = parent::acquire();

        if (! $acquired) {
            $this->instrumentedStore->recordFailedAcquisition();
        }

        return $acquired;
    }
}
