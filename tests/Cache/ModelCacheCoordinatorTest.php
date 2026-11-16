<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\CarbonInterval;
use Closure;
use Hypervel\Cache\ArrayLock;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Exceptions\UnsupportedModelCacheStoreException;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\Lock;
use Hypervel\Cache\MemoizedStore;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Engine\Channel;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class ModelCacheCoordinatorTest extends TestCase
{
    use VerifiesDoubles;

    protected Closure $source;

    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        $this->container->instance('db.transactions', new DatabaseTransactionsManager);
        $this->source = static fn (): never => throw new RuntimeException('A source model must not be constructed without a transaction.');
    }

    public function testCachedPositiveAndNullValuesReturnWithoutLockingOrReading(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = new Repository(new ArrayStore);
        $reads = 0;

        $this->assertSame('user', $coordinator->fill(
            $repository,
            $this->source,
            'positive',
            300,
            function () use (&$reads): string {
                ++$reads;

                return 'user';
            },
        ));
        $this->assertNull($coordinator->fill(
            $repository,
            $this->source,
            'null',
            300,
            function () use (&$reads): null {
                ++$reads;

                return null;
            },
        ));

        $this->assertSame('user', $coordinator->fill(
            $repository,
            $this->source,
            'positive',
            300,
            fn () => throw new RuntimeException('The source must not be read on a cache hit.'),
        ));
        $this->assertNull($coordinator->fill(
            $repository,
            $this->source,
            'null',
            300,
            fn () => throw new RuntimeException('The source must not be read on a cached-null hit.'),
        ));
        $this->assertSame(2, $reads);
    }

    public function testColdFillDoubleChecksAfterLockAcquisition(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $seedRepository = new Repository(new ArrayStore);
        $coordinator->fill($seedRepository, $this->source, 'seed', 300, fn (): string => 'published');
        $envelope = $seedRepository->getStore()->get('seed');

        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(RefreshableLock::class);

        $repository->expects('get')->times(2)->with('key')->returns(null, $envelope);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->never();
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('get')->with(Argument::type(Closure::class))->resolves(
            static fn (Closure $callback): mixed => $callback(),
        );

        $this->assertSame('published', $coordinator->fill(
            $repository,
            $this->source,
            'key',
            300,
            fn () => throw new RuntimeException('The source must not be read after a warm double-check.'),
        ));
    }

    public function testColdFillDoubleChecksPastAMemoizedMissAndRemembersTheFill(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $backingRepository = new Repository(new ArrayStore);
        $memoizedRepository = new Repository(new MemoizedStore('array', $backingRepository));

        $this->assertNull($memoizedRepository->get('key'));

        // Another request fills the entry after this coroutine remembered the miss.
        $coordinator->fill($backingRepository, $this->source, 'key', 300, fn (): string => 'published');

        $this->assertSame('published', $coordinator->fill(
            $memoizedRepository,
            $this->source,
            'key',
            300,
            fn (): never => throw new RuntimeException('The source must not be read after a warm double-check.'),
        ));

        $backingRepository->forget('key');

        $this->assertSame('published', $coordinator->fill(
            $memoizedRepository,
            $this->source,
            'key',
            300,
            fn (): never => throw new RuntimeException('The double-checked value must be reused without locking.'),
        ));
    }

    public function testPublishedFillIsRememberedForTheCurrentCoroutine(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $backingRepository = new Repository(new ArrayStore);
        $memoizedRepository = new Repository(new MemoizedStore('array', $backingRepository));

        $this->assertSame('user', $coordinator->fill($memoizedRepository, $this->source, 'key', 300, fn (): string => 'user'));

        $backingRepository->forget('key');

        $this->assertSame('user', $coordinator->fill(
            $memoizedRepository,
            $this->source,
            'key',
            300,
            fn (): never => throw new RuntimeException('The published value must be reused without locking.'),
        ));
    }

    public function testFailedPublicationIsNotRemembered(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $memoizedRepository = new Repository(new MemoizedStore(
            'array',
            new Repository(new FailingWriteCoordinatorArrayStore),
        ));
        $reads = 0;

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertSame('user', $coordinator->fill(
                $memoizedRepository,
                $this->source,
                'key',
                300,
                function () use (&$reads): string {
                    ++$reads;

                    return 'user';
                },
            ));
        }

        $this->assertSame(2, $reads);
    }

    public function testLazyWriterPublicationIsNotRemembered(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $memoizedRepository = new Repository(new MemoizedStore('array', new Repository(new ArrayStore)));
        $writerRepository = new Repository(new ArrayStore);
        $reads = 0;

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertSame('user', $coordinator->fill(
                $memoizedRepository,
                $this->source,
                'key',
                300,
                function () use (&$reads): string {
                    ++$reads;

                    return 'user';
                },
                writeCache: fn (): CacheRepository => $writerRepository,
            ));
        }

        $this->assertSame(2, $reads);
    }

    public function testLazyWriterIsResolvedOnlyWhenPublishing(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $plainRepository = new Repository(new ArrayStore);
        $writerRepository = new Repository(new ArrayStore);
        $writerResolutions = 0;

        $this->assertSame('user', $coordinator->fill(
            $plainRepository,
            $this->source,
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
            $this->source,
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
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = new Repository(new ArrayStore);
        $reads = 0;

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertNull($coordinator->fill(
                $repository,
                $this->source,
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

    public function testNonPublishingNullReadDoesNotRefreshTheLease(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(RefreshableLock::class);

        $repository->expects('get')->times(2)->with('key')->returns(null);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->never();
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('get')->with(Argument::type(Closure::class))->resolves(
            static fn (Closure $callback): mixed => $callback(),
        );
        $lock->expects('refresh')->never();

        $this->assertNull($coordinator->fill(
            $repository,
            $this->source,
            'key',
            300,
            fn (): null => null,
            cacheNull: false,
        ));
    }

    public function testFailedLockAcquisitionReadsWithoutPublishingOrResolvingWriter(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(RefreshableLock::class);
        $writerResolutions = 0;

        $repository->expects('get')->with('key')->returns(null);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->never();
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('get')->with(Argument::type(Closure::class))->returns(false);

        $this->assertSame('database', $coordinator->fill(
            $repository,
            $this->source,
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

    public function testColdFillRefreshesAfterReadingAndBeforeResolvingWriterOrPublishing(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(RefreshableLock::class);
        $operations = [];

        $repository->expects('get')->times(2)->with('key')->returns(null);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->with(
            'key',
            Argument::satisfies(static fn (mixed $value): bool => is_array($value)),
            300,
        )->resolves(function () use (&$operations): bool {
            $operations[] = 'put';

            return true;
        });
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('get')->with(Argument::type(Closure::class))->resolves(
            static fn (Closure $callback): mixed => $callback(),
        );
        $lock->expects('refresh')->with(Argument::none())->resolves(
            function () use (&$operations): bool {
                $operations[] = 'refresh';

                return true;
            },
        );

        $this->assertSame('database', $coordinator->fill(
            $repository,
            $this->source,
            'key',
            300,
            function () use (&$operations): string {
                $operations[] = 'read';

                return 'database';
            },
            writeCache: function () use (&$operations, $repository): CacheRepository {
                $operations[] = 'writer';

                return $repository;
            },
        ));
        $this->assertSame(['read', 'refresh', 'writer', 'put'], $operations);
    }

    public function testColdFillThatLostItsLeaseReturnsTheReadValueWithoutPublishing(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(RefreshableLock::class);
        $operations = [];

        $repository->expects('get')->times(2)->with('key')->returns(null);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->never();
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('get')->with(Argument::type(Closure::class))->resolves(
            static fn (Closure $callback): mixed => $callback(),
        );
        $lock->expects('refresh')->with(Argument::none())->resolves(
            function () use (&$operations): bool {
                $operations[] = 'refresh';

                return false;
            },
        );

        $this->assertSame('database', $coordinator->fill(
            $repository,
            $this->source,
            'key',
            300,
            function () use (&$operations): string {
                $operations[] = 'read';

                return 'database';
            },
            writeCache: function () use (&$operations, $repository): CacheRepository {
                $operations[] = 'writer';

                return $repository;
            },
        ));
        $this->assertSame(['read', 'refresh'], $operations);
    }

    public function testFalseCallbackResultIsNotMistakenForFailedAcquisition(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(RefreshableLock::class);

        $repository->expects('get')->times(2)->with('key')->returns(null);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->with(
            'key',
            Argument::satisfies(static fn (mixed $value): bool => is_array($value)),
            300,
        )->returns(true);
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('get')->with(Argument::type(Closure::class))->resolves(
            static fn (Closure $callback): mixed => $callback(),
        );
        $lock->expects('refresh')->with(Argument::none())->returns(true);

        $reads = 0;
        $this->assertFalse($coordinator->fill(
            $repository,
            $this->source,
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
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = new CoordinatorTestLock;

        $repository->expects('get')->times(2)->with('key')->returns(null);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->never();
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);

        try {
            $coordinator->fill(
                $repository,
                $this->source,
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
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $fillLock = Double::for(RefreshableLock::class);
        $invalidateLock = Double::for(RefreshableLock::class);
        $lockNames = [];

        $repository->expects('get')->with('a-very-long-data-key')->returns(null);
        $repository->expects('getStore')->times(2)->returns($store);
        $repository->expects('forget')->with('a-very-long-data-key')->returns(true);
        $store->expects('lock')->times(2)->resolves(
            function (string $name, int $seconds) use (&$lockNames, $fillLock, $invalidateLock): LockContract {
                $lockNames[] = [$name, $seconds];

                return count($lockNames) === 1 ? $fillLock : $invalidateLock;
            },
        );
        $fillLock->expects('get')->with(Argument::type(Closure::class))->returns(false);
        $invalidateLock->expects('betweenBlockedAttemptsSleepFor')->with(25);
        $invalidateLock->expects('block')->with(11, Argument::type(Closure::class))->resolves(
            static fn (int $seconds, Closure $callback): mixed => $callback(),
        );

        $this->assertSame('uncached', $coordinator->fill(
            $repository,
            $this->source,
            'a-very-long-data-key',
            300,
            fn (): string => 'uncached',
        ));
        $this->assertTrue($coordinator->invalidate($repository, 'a-very-long-data-key'));

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
        $coordinator = new ModelCacheCoordinator($this->container);

        parallel([
            'fill' => function () use ($contention, $coordinator, $repository, $sourceRead): void {
                $coordinator->fill(
                    $repository,
                    $this->source,
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

        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = new CoordinatorTestLock(failFirstAcquisition: true);

        $repository->expects('getStore')->returns($store);
        $repository->expects('forget')->with('key')->returns(true);
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);

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
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(RefreshableLock::class);

        $repository->expects('getStore')->returns($store);
        $repository->expects('forget')->never();
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('betweenBlockedAttemptsSleepFor')->with(25);
        $lock->expects('block')->with(11, Argument::type(Closure::class))->throws(new LockTimeoutException);

        $this->expectException(LockTimeoutException::class);

        $coordinator->invalidate($repository, 'key');
    }

    public function testUnsupportedStoreIsRejectedOnlyAfterAMiss(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $seedRepository = new Repository(new ArrayStore);
        $coordinator->fill($seedRepository, $this->source, 'seed', 300, fn (): string => 'cached');
        $envelope = $seedRepository->getStore()->get('seed');

        $hitRepository = Double::for(CacheRepository::class);
        $hitRepository->expects('get')->with('key')->returns($envelope);
        $hitRepository->expects('getStore')->never();

        $this->assertSame('cached', $coordinator->fill(
            $hitRepository,
            $this->source,
            'key',
            300,
            fn () => throw new RuntimeException('The source must not be read on a cache hit.'),
        ));

        $missRepository = Double::for(CacheRepository::class);
        $unsupportedStore = Double::for(Store::class);
        $missRepository->expects('get')->with('key')->returns(null);
        $missRepository->expects('getStore')->returns($unsupportedStore);

        $this->expectExceptionMessageIsOrContains('does not provide atomic locks');

        $coordinator->fill($missRepository, $this->source, 'key', 300, fn (): string => 'database');
    }

    public function testStackAndFailoverTopologiesAreRejectedThroughDirectAndMemoizedStores(): void
    {
        $failoverStore = Double::for(FailoverStore::class);
        $failoverStore->expects('getRaw')->times(2)->with('key')->returns(null);
        $failoverStore->expects('getPrefix')->returns('');

        $stores = [
            'stack' => new StackStore([new ArrayStore]),
            'failover' => $failoverStore,
        ];

        foreach ($stores as $name => $store) {
            $repository = new Repository($store);

            foreach ([
                "direct {$name}" => $repository,
                "memoized {$name}" => new Repository(new MemoizedStore($name, $repository)),
            ] as $label => $candidate) {
                try {
                    (new ModelCacheCoordinator($this->container))->fill(
                        $candidate,
                        $this->source,
                        'key',
                        300,
                        fn (): string => 'database',
                    );
                } catch (UnsupportedModelCacheStoreException $exception) {
                    $this->assertStringContainsString(
                        'cannot guarantee that cached values and locks use the same backend',
                        $exception->getMessage(),
                        $label,
                    );

                    continue;
                }

                $this->fail("Expected {$label} to be rejected for coordinated model caching.");
            }
        }
    }

    public function testNonRefreshableLockIsRejectedOnlyAfterAMiss(): void
    {
        $coordinator = new ModelCacheCoordinator($this->container);
        $repository = Double::for(CacheRepository::class);
        $store = Double::for(Store::class, LockProvider::class);
        $lock = Double::for(LockContract::class);

        $repository->expects('get')->with('key')->returns(null);
        $repository->expects('getStore')->returns($store);
        $repository->expects('put')->never();
        $store->expects('lock')->with(Argument::type('string'), 10)->returns($lock);
        $lock->expects('get')->never();

        $this->expectExceptionMessageIsOrContains('does not provide refreshable atomic locks');

        $coordinator->fill($repository, $this->source, 'key', 300, fn (): string => 'database');
    }
}

class CoordinatorTestLock extends Lock implements RefreshableLock
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

    public function refresh(?int $seconds = null): bool
    {
        return true;
    }

    public function getRemainingLifetime(): ?float
    {
        return null;
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->owner;
    }
}

class FailingWriteCoordinatorArrayStore extends ArrayStore
{
    /**
     * Report a failed write without storing the item.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return false;
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
