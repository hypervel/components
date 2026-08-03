<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Cache\SwooleTableState;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class CacheSwooleStoreIntervalTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        IntervalLostClaimProbe::reset();
        IntervalReentryProbe::reset();

        $this->tempDir = ParallelTesting::tempDir('CacheSwooleStoreIntervalTest');
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        IntervalLostClaimProbe::reset();
        IntervalReentryProbe::reset();

        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testIntervalRegistersMetadataAndSharedIndex(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $store->interval('foo', fn () => 'bar', 5);

        $metadataKey = $this->metadataKey($store, 'foo');
        $indexKey = $this->indexKey($store, $metadataKey);
        $metadata = $this->metadata($state, $metadataKey);
        $index = $this->index($state, $indexKey);

        $this->assertFalse($state->table()->get('interval-foo'));
        $this->assertSame('foo', $metadata['key']);
        $this->assertSame($metadataKey, $metadata['metadataKey']);
        $this->assertIsString($metadata['resolver']);
        $this->assertInstanceOf(SerializableClosure::class, unserialize($metadata['resolver']));
        $this->assertNull($metadata['lastRefreshedAt']);
        $this->assertNull($metadata['refreshingAt']);
        $this->assertSame(5, $metadata['refreshInterval']);
        $this->assertSame([$metadataKey => true], $index);
    }

    public function testIntervalMetadataSerializationDoesNotRunCapturedObjectMagicInsideRowLock(): void
    {
        $state = $this->createState();
        $store = new InstrumentedIntervalSwooleStore($state);
        IntervalMetadataSerializationProbe::reset();
        $probe = new IntervalMetadataSerializationProbe;

        $store->interval('foo', function () use ($probe) {
            return $probe->value();
        }, 5);

        $metadata = $this->metadata($state, $this->metadataKey($store, 'foo'));

        $this->assertIsString($metadata['resolver']);
        $this->assertSame(0, IntervalMetadataSerializationProbe::$insideSleeps);
        $this->assertSame(0, IntervalMetadataSerializationProbe::$insideWakeups);
        $this->assertSame(1, IntervalMetadataSerializationProbe::$outsideSleeps);
        $this->assertSame(0, IntervalMetadataSerializationProbe::$outsideWakeups);

        $store->refreshIntervalCaches();

        $this->assertSame('bar', $store->get('foo'));
        $this->assertSame(0, IntervalMetadataSerializationProbe::$insideSleeps);
        $this->assertSame(0, IntervalMetadataSerializationProbe::$insideWakeups);
        $this->assertSame(1, IntervalMetadataSerializationProbe::$outsideWakeups);
    }

    public function testIntervalRegistrationIsIdempotentForLocalAndSharedIndexes(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $store->interval('foo', fn () => 'first', 5);
        $store->interval('foo', fn () => 'second', 10);

        $metadataKey = $this->metadataKey($store, 'foo');
        $index = $this->index($state, $this->indexKey($store, $metadataKey));

        $this->assertSame([$metadataKey => true], $index);
        $this->assertSame(['foo' => true], $this->localIntervals($store));
        $this->assertSame(10, $this->metadata($state, $metadataKey)['refreshInterval']);
    }

    public function testIntervalReregistrationPreservesLastRefreshTimestamp(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);
        IntervalResolverState::$attempts = 0;

        $store->interval('foo', function () {
            return ++IntervalResolverState::$attempts;
        }, 5);
        $store->refreshIntervalCaches();
        $this->assertSame(1, $store->get('foo'));

        $metadataKey = $this->metadataKey($store, 'foo');
        $lastRefreshedAt = $this->metadata($state, $metadataKey)['lastRefreshedAt'];

        CarbonImmutable::setTestNow('2000-01-01 00:00:01');

        $store->interval('foo', fn () => 999, 5);

        $this->assertSame($lastRefreshedAt, $this->metadata($state, $metadataKey)['lastRefreshedAt']);

        $store->refreshIntervalCaches();

        $this->assertSame(1, $store->get('foo'));
        $this->assertSame(1, IntervalResolverState::$attempts);
    }

    public function testIntervalReregistrationPreservesFreshRefreshClaim(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);
        $count = 0;

        $store->interval('foo', function () use (&$count) {
            return ++$count;
        }, 5);

        $metadataKey = $this->metadataKey($store, 'foo');
        $metadata = $this->metadata($state, $metadataKey);
        $metadata['refreshingAt'] = $this->currentTimestamp();
        $this->putMetadata($state, $metadataKey, $metadata);

        $store->interval('foo', function () use (&$count) {
            return ++$count;
        }, 1);

        $this->assertSame($this->currentTimestamp(), $this->metadata($state, $metadataKey)['refreshingAt']);

        $store->refreshIntervalCaches();

        $this->assertSame(0, $count);
    }

    public function testIntervalReregistrationUpdatesResolverAndRefreshIntervalForFutureRefreshes(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);

        $store->interval('foo', fn () => 'first', 10);
        $store->refreshIntervalCaches();
        $this->assertSame('first', $store->get('foo'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:01');

        $store->interval('foo', fn () => 'second', 2);
        $store->refreshIntervalCaches();
        $this->assertSame('first', $store->get('foo'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:02');

        $store->refreshIntervalCaches();

        $metadata = $this->metadata($state, $this->metadataKey($store, 'foo'));

        $this->assertSame('second', $store->get('foo'));
        $this->assertSame(2, $metadata['refreshInterval']);
    }

    public function testSameInstanceFallbackResolvesBeforeFirstTimerTick(): void
    {
        $store = $this->createStore();

        $store->interval('foo', fn () => 'bar', 5);

        $this->assertSame('bar', $store->get('foo'));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testDifferentStoreReturnsNullBeforeFirstTimerTick(): void
    {
        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $readerStore = $this->createStore($state);

        $workerStore->interval('foo', fn () => 'bar', 5);

        $this->assertNull($readerStore->get('foo'));
    }

    public function testRefresherStoreRefreshesIntervalsFromSharedIndex(): void
    {
        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $refresherStore = $this->createStore($state);

        $workerStore->interval('foo', fn () => 'bar', 5);

        $refresherStore->refreshIntervalCaches();

        $this->assertSame('bar', $workerStore->get('foo'));
        $this->assertSame('bar', $refresherStore->get('foo'));
    }

    public function testSerializableClassPolicyDoesNotApplyToIntervalResolvers(): void
    {
        $state = $this->createState();
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $workerStore = $this->createStore($state, serializableClassPolicy: $policy);
        $refresherStore = $this->createStore($state, serializableClassPolicy: $policy);

        $workerStore->interval('foo', fn () => 'bar', 5);

        $refresherStore->refreshIntervalCaches();

        $this->assertSame('bar', $refresherStore->get('foo'));
    }

    public function testRefresherStoreRefreshesMultipleIntervalsFromSharedIndex(): void
    {
        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $refresherStore = $this->createStore($state);
        $keys = $this->keysWithSharedIndexShard($workerStore);

        foreach ($keys as $key) {
            $workerStore->interval($key, fn () => "value-{$key}", 5);
        }

        $metadataKeys = array_map(fn (string $key): string => $this->metadataKey($workerStore, $key), $keys);
        $sharedShardKey = $this->indexKey($workerStore, $metadataKeys[0]);
        $sharedShard = $this->index($state, $sharedShardKey);

        $this->assertSame($sharedShardKey, $this->indexKey($workerStore, $metadataKeys[1]));
        $this->assertArrayHasKey($metadataKeys[0], $sharedShard);
        $this->assertArrayHasKey($metadataKeys[1], $sharedShard);

        $refresherStore->refreshIntervalCaches();

        foreach ($keys as $key) {
            $this->assertSame("value-{$key}", $workerStore->get($key));
            $this->assertSame("value-{$key}", $refresherStore->get($key));
        }
    }

    public function testNullReturningIntervalResolverStoresLivePublicRow(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);
        IntervalResolverState::$attempts = 0;

        $store->interval('foo', function () {
            ++IntervalResolverState::$attempts;

            return null;
        }, 5);

        $this->assertNull($store->get('foo'));
        $this->assertNotFalse($this->userRow($state, $store, 'foo'));

        $this->assertNull($store->get('foo'));
        $this->assertSame(1, IntervalResolverState::$attempts);
    }

    public function testRefreshOnlyRunsWhenIntervalIsDue(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $refresherStore = $this->createStore($state);

        $workerStore->interval('foo', fn () => CarbonImmutable::now()->getTimestamp(), 5);

        $refresherStore->refreshIntervalCaches();
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $refresherStore->get('foo'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:04');
        $refresherStore->refreshIntervalCaches();
        $this->assertSame(CarbonImmutable::parse('2000-01-01 00:00:00')->getTimestamp(), $refresherStore->get('foo'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:06');
        $refresherStore->refreshIntervalCaches();
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $refresherStore->get('foo'));
    }

    public function testSuccessfulRefreshUpdatesMetadata(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00.123456');

        $state = $this->createState();
        $store = $this->createStore($state);
        $store->interval('foo', fn () => 'bar', 5);
        $metadataKey = $this->metadataKey($store, 'foo');

        $store->refreshIntervalCaches();

        $metadata = $this->metadata($state, $metadataKey);

        $this->assertSame($this->currentTimestamp(), $metadata['lastRefreshedAt']);
        $this->assertNull($metadata['refreshingAt']);
    }

    public function testSlowSuccessfulRefreshUsesCommitTimestampForCadence(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);
        $store->interval('foo', function () {
            CarbonImmutable::setTestNow('2000-01-01 00:00:03.123456');

            return 'bar';
        }, 5);

        $metadataKey = $this->metadataKey($store, 'foo');

        $store->refreshIntervalCaches();

        $this->assertSame(
            CarbonImmutable::parse('2000-01-01 00:00:03.123456')->getPreciseTimestamp(6) / 1000000,
            $this->metadata($state, $metadataKey)['lastRefreshedAt']
        );
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testFreshRefreshClaimPreventsOverlappingRefresh(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);
        $count = 0;

        $store->interval('foo', function () use (&$count) {
            return ++$count;
        }, 5);

        $metadataKey = $this->metadataKey($store, 'foo');
        $metadata = $this->metadata($state, $metadataKey);
        $metadata['refreshingAt'] = $this->currentTimestamp();
        $this->putMetadata($state, $metadataKey, $metadata);

        $store->refreshIntervalCaches();

        $this->assertSame(0, $count);
        $this->assertNull($store->get('foo'));
    }

    public function testStaleRefreshClaimCanBeReclaimed(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:05:01');

        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $refresherStore = $this->createStore($state);

        $workerStore->interval('foo', fn () => 'bar', 5);

        $metadataKey = $this->metadataKey($workerStore, 'foo');
        $metadata = $this->metadata($state, $metadataKey);
        $metadata['refreshingAt'] = $this->currentTimestamp() - 301.0;
        $this->putMetadata($state, $metadataKey, $metadata);

        $refresherStore->refreshIntervalCaches();

        $this->assertSame('bar', $refresherStore->get('foo'));
        $this->assertNull($this->metadata($state, $metadataKey)['refreshingAt']);
    }

    public function testRefreshIntervalUsesDoubledIntervalWhenItExceedsClaimTimeout(): void
    {
        $store = $this->createStore();

        $this->assertFalse($this->invoke($store, 'intervalClaimIsStale', 0.0, 399.999999, 200));
        $this->assertTrue($this->invoke($store, 'intervalClaimIsStale', 0.0, 400.0, 200));
    }

    public function testStaleRefresherCannotOverwriteNewerCommittedValue(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $refresherStore = $this->createStore($state);
        IntervalReentryProbe::$refresherStore = $refresherStore;

        $workerStore->interval('foo', function () {
            ++IntervalReentryProbe::$attempts;

            if (IntervalReentryProbe::$attempts === 1) {
                CarbonImmutable::setTestNow('2000-01-01 00:05:01');
                IntervalReentryProbe::$refresherStore->refreshIntervalCaches();

                return 'A';
            }

            return 'B';
        }, 5);

        $metadataKey = $this->metadataKey($workerStore, 'foo');

        $workerStore->refreshIntervalCaches();

        $metadata = $this->metadata($state, $metadataKey);

        $this->assertSame(2, IntervalReentryProbe::$attempts);
        $this->assertSame('B', $workerStore->get('foo'));
        $this->assertNull($metadata['refreshingAt']);
        $this->assertSame(
            CarbonImmutable::parse('2000-01-01 00:05:01')->getPreciseTimestamp(6) / 1000000,
            $metadata['lastRefreshedAt']
        );
    }

    public function testLostClaimBeforeCommitDoesNotWriteResolverResult(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);
        IntervalLostClaimProbe::$state = $state;
        $metadataKey = $this->metadataKey($store, 'foo');

        $store->interval('foo', fn () => IntervalLostClaimProbe::loseClaim($metadataKey), 5);

        $store->refreshIntervalCaches();

        $metadata = $this->metadata($state, $metadataKey);

        $this->assertFalse($this->userRow($state, $store, 'foo'));
        $this->assertNull($metadata['lastRefreshedAt']);
        $this->assertSame(123.456789, $metadata['refreshingAt']);
    }

    public function testCompletionAndClaimClearingDoNotOverwriteNewerClaim(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $store->interval('foo', fn () => 'bar', 5);

        $metadataKey = $this->metadataKey($store, 'foo');
        $metadata = $this->metadata($state, $metadataKey);
        $metadata['refreshingAt'] = 2.123456;
        $this->putMetadata($state, $metadataKey, $metadata);

        $this->invoke($store, 'completeIntervalRefresh', $metadataKey, 1.123456);
        $this->assertSame(2.123456, $this->metadata($state, $metadataKey)['refreshingAt']);
        $this->assertNull($this->metadata($state, $metadataKey)['lastRefreshedAt']);

        $this->invoke($store, 'clearIntervalClaim', $metadataKey, 1.123456);
        $this->assertSame(2.123456, $this->metadata($state, $metadataKey)['refreshingAt']);
    }

    public function testSameInstanceFallbackDuringFreshClaimReturnsNullWithoutRunningResolver(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);
        $count = 0;

        $store->interval('foo', function () use (&$count) {
            return ++$count;
        }, 5);

        $metadataKey = $this->metadataKey($store, 'foo');
        $metadata = $this->metadata($state, $metadataKey);
        $metadata['refreshingAt'] = $this->currentTimestamp();
        $this->putMetadata($state, $metadataKey, $metadata);

        $this->assertNull($store->get('foo'));
        $this->assertSame(0, $count);
    }

    public function testFlushPreservesMetadataAndIndexRowsForRefresh(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $refresherStore = $this->createStore($state);

        $workerStore->interval('foo', fn () => 'bar', 5);
        $refresherStore->refreshIntervalCaches();
        $this->assertSame('bar', $refresherStore->get('foo'));

        $this->assertTrue($refresherStore->flush());
        $this->assertNull($refresherStore->get('foo'));
        $this->assertNotFalse($state->table()->get($this->metadataKey($workerStore, 'foo')));
        $this->assertNotFalse($state->table()->get($this->indexKey($workerStore, $this->metadataKey($workerStore, 'foo'))));

        CarbonImmutable::setTestNow('2000-01-01 00:00:05');

        $refresherStore->refreshIntervalCaches();

        $this->assertSame('bar', $refresherStore->get('foo'));
    }

    public function testGenericMissDoesNotConsultSharedIntervalIndex(): void
    {
        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $otherStore = $this->createStore($state);
        $count = 0;

        $workerStore->interval('foo', function () use (&$count) {
            return ++$count;
        }, 5);

        $this->assertNull($otherStore->get('foo'));
        $this->assertSame(0, $count);
    }

    public function testIndexRowsArePermanentWhenRegistered(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState();
        $store = $this->createStore($state);

        $store->interval('foo', fn () => 'bar', 5);
        $indexKey = $this->indexKey($store, $this->metadataKey($store, 'foo'));

        $this->assertSame(PHP_FLOAT_MAX, $state->table()->get($indexKey)['expiration']);
    }

    public function testStaleCleanupAndEvictionSkipIntervalControlRows(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $state = $this->createState(rows: 8);
        $store = $this->createStore(
            state: $state,
            memoryLimitBuffer: 1.0,
            evictionProportion: 1.0
        );

        $store->interval('foo', fn () => 'bar', 5);
        $metadataKey = $this->metadataKey($store, 'foo');
        $indexKey = $this->indexKey($store, $metadataKey);

        $this->rewriteRowExpiration($state, $metadataKey, $this->currentTimestamp() - 1);
        $this->rewriteRowExpiration($state, $indexKey, $this->currentTimestamp() - 1);

        $store->put('user', 'value', 60);
        $store->evictRecords();

        $this->assertNotFalse($state->table()->get($metadataKey));
        $this->assertNotFalse($state->table()->get($indexKey));
    }

    public function testThrowingTimerResolverIsReportedAndCanRetry(): void
    {
        $container = new Container;
        $handler = m::spy(ExceptionHandler::class);
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $state = $this->createState();
        $store = $this->createStore($state);
        $readerStore = $this->createStore($state);
        $exception = new RuntimeException('refresh failed');
        IntervalResolverState::$attempts = 0;

        $store->interval('foo', function () use ($exception) {
            ++IntervalResolverState::$attempts;

            if (IntervalResolverState::$attempts === 1) {
                throw $exception;
            }

            return 'bar';
        }, 5);

        $metadataKey = $this->metadataKey($store, 'foo');

        $store->refreshIntervalCaches();

        $handler->shouldHaveReceived('report')->with(m::on(
            fn (Throwable $e): bool => $e::class === $exception::class
                && $e->getMessage() === $exception->getMessage()
        ))->once();
        $this->assertFalse($this->userRow($state, $readerStore, 'foo'));
        $this->assertNull($this->metadata($state, $metadataKey)['lastRefreshedAt']);
        $this->assertNull($this->metadata($state, $metadataKey)['refreshingAt']);

        $store->refreshIntervalCaches();

        $this->assertSame('bar', $readerStore->get('foo'));
    }

    public function testThrowingSameInstanceFallbackRethrowsAndClearsClaim(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);
        $exception = new RuntimeException('fallback failed');

        $store->interval('foo', fn () => throw $exception, 5);

        $metadataKey = $this->metadataKey($store, 'foo');

        try {
            $store->get('foo');
            $this->fail('Expected interval fallback exception was not thrown.');
        } catch (Throwable $e) {
            $this->assertSame($exception::class, $e::class);
            $this->assertSame($exception->getMessage(), $e->getMessage());
        }

        $this->assertNull($this->metadata($state, $metadataKey)['refreshingAt']);
        $this->assertNull($this->metadata($state, $metadataKey)['lastRefreshedAt']);
    }

    public function testIntervalExceptionFallsBackToStderrWhenNoExceptionHandlerIsBound(): void
    {
        $scriptPath = $this->tempDir . '/interval-stderr.php';
        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';

        file_put_contents($scriptPath, <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1];

use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Container\Container;
use RuntimeException;
use Throwable;

class ReportIntervalExceptionProbeStore extends SwooleStore
{
    public function report(Throwable $e): void
    {
        $this->reportIntervalException($e);
    }
}

Container::setInstance(new Container);

$state = (new SwooleTableManager(new Container))->createState(8, 1024, 0.2, 12345);
$store = new ReportIntervalExceptionProbeStore($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
$store->report(new RuntimeException('refresh failed'));
PHP);

        $process = new Process([PHP_BINARY, $scriptPath, $autoloadPath]);
        $process->mustRun();

        $this->assertStringContainsString('refresh failed', $process->getErrorOutput());
    }

    public function testFailedPublicValueWriteClearsClaimAndReportsFailure(): void
    {
        $container = new Container;
        $handler = m::spy(ExceptionHandler::class);
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $state = $this->createState();
        $workerStore = $this->createStore($state);
        $refresherStore = new FailingIntervalValueSwooleStore($state);

        $workerStore->interval('foo', fn () => 'bar', 5);

        $refresherStore->refreshIntervalCaches();

        $handler->shouldHaveReceived('report')->with(m::on(
            fn (RuntimeException $e): bool => $e->getMessage() === 'Unable to refresh Swoole interval cache [foo].'
        ))->once();

        $metadata = $this->metadata($state, $this->metadataKey($workerStore, 'foo'));

        $this->assertNull($metadata['refreshingAt']);
        $this->assertNull($metadata['lastRefreshedAt']);
        $this->assertFalse($this->userRow($state, $workerStore, 'foo'));
    }

    private function createState(
        int $rows = 128,
        int $bytes = 65536,
        float $conflictProportion = 0.2,
        int $hashSeed = 12345
    ): SwooleTableState {
        return (new SwooleTableManager(new Container))
            ->createState($rows, $bytes, $conflictProportion, $hashSeed);
    }

    private function createStore(
        ?SwooleTableState $state = null,
        string $policy = SwooleStore::EVICTION_POLICY_TTL,
        float $memoryLimitBuffer = 0.05,
        float $evictionProportion = 0.05,
        SerializableClassPolicy $serializableClassPolicy = new SerializableClassPolicy,
    ): SwooleStore {
        return new SwooleStore(
            $state ?? $this->createState(),
            $memoryLimitBuffer,
            $policy,
            $evictionProportion,
            serializableClassPolicy: $serializableClassPolicy,
        );
    }

    private function invoke(SwooleStore $store, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($store, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($store, ...$arguments);
    }

    private function metadataKey(SwooleStore $store, string $key): string
    {
        return $this->invoke($store, 'intervalKey', $key);
    }

    private function indexKey(SwooleStore $store, string $metadataKey): string
    {
        return $this->invoke($store, 'intervalIndexKey', $metadataKey);
    }

    private function userKey(SwooleStore $store, string $key): string
    {
        return $this->invoke($store, 'userKey', $key);
    }

    /**
     * Get three interval keys, including two that share an interval index shard.
     *
     * @return list<string>
     */
    private function keysWithSharedIndexShard(SwooleStore $store): array
    {
        $keysByShard = [];

        for ($i = 0; $i < 256; ++$i) {
            $key = "interval-key-{$i}";
            $metadataKey = $this->metadataKey($store, $key);
            $indexKey = $this->indexKey($store, $metadataKey);
            $keysByShard[$indexKey][] = $key;

            if (count($keysByShard[$indexKey]) === 2) {
                return [$keysByShard[$indexKey][0], $keysByShard[$indexKey][1], "interval-key-extra-{$i}"];
            }
        }

        $this->fail('Unable to find two interval keys that share an index shard.');
    }

    private function metadata(SwooleTableState $state, string $metadataKey): array
    {
        return unserialize($state->table()->get($metadataKey)['value']);
    }

    private function putMetadata(SwooleTableState $state, string $metadataKey, array $metadata): void
    {
        $row = $state->table()->get($metadataKey);

        $state->table()->set($metadataKey, [
            'value' => serialize($metadata),
            'expiration' => $row['expiration'],
        ]);
    }

    private function index(SwooleTableState $state, string $indexKey): array
    {
        return unserialize($state->table()->get($indexKey)['value']);
    }

    private function localIntervals(SwooleStore $store): array
    {
        $property = new ReflectionProperty($store, 'intervals');
        $property->setAccessible(true);

        return $property->getValue($store);
    }

    private function userRow(SwooleTableState $state, SwooleStore $store, string $key): array|false
    {
        return $state->table()->get($this->userKey($store, $key));
    }

    private function rewriteRowExpiration(SwooleTableState $state, string $key, float $expiration): void
    {
        $row = $state->table()->get($key);
        $row['expiration'] = $expiration;

        $state->table()->set($key, $row);
    }

    private function currentTimestamp(): float
    {
        return CarbonImmutable::now()->getPreciseTimestamp(6) / 1000000;
    }
}

class FailingIntervalValueSwooleStore extends SwooleStore
{
    public function __construct(SwooleTableState $state)
    {
        parent::__construct($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
    }

    public function forever(string $key, mixed $value): bool
    {
        return false;
    }
}

class InstrumentedIntervalSwooleStore extends SwooleStore
{
    public function __construct(SwooleTableState $state)
    {
        parent::__construct($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
    }

    protected function getIntervalMetadataByInternalKey(string $metadataKey): ?array
    {
        IntervalMetadataSerializationProbe::$insideMetadataSerialization = true;

        try {
            return parent::getIntervalMetadataByInternalKey($metadataKey);
        } finally {
            IntervalMetadataSerializationProbe::$insideMetadataSerialization = false;
        }
    }

    protected function putIntervalMetadataByInternalKey(string $metadataKey, array $metadata): bool
    {
        IntervalMetadataSerializationProbe::$insideMetadataSerialization = true;

        try {
            return parent::putIntervalMetadataByInternalKey($metadataKey, $metadata);
        } finally {
            IntervalMetadataSerializationProbe::$insideMetadataSerialization = false;
        }
    }
}

class IntervalMetadataSerializationProbe
{
    public static bool $insideMetadataSerialization = false;

    public static int $insideSleeps = 0;

    public static int $insideWakeups = 0;

    public static int $outsideSleeps = 0;

    public static int $outsideWakeups = 0;

    public static function reset(): void
    {
        self::$insideMetadataSerialization = false;
        self::$insideSleeps = 0;
        self::$insideWakeups = 0;
        self::$outsideSleeps = 0;
        self::$outsideWakeups = 0;
    }

    public function value(): string
    {
        return 'bar';
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        if (self::$insideMetadataSerialization) {
            ++self::$insideSleeps;
        } else {
            ++self::$outsideSleeps;
        }

        return [];
    }

    public function __wakeup(): void
    {
        if (self::$insideMetadataSerialization) {
            ++self::$insideWakeups;
        } else {
            ++self::$outsideWakeups;
        }
    }
}

class IntervalResolverState
{
    public static int $attempts = 0;
}

class IntervalLostClaimProbe
{
    public static ?SwooleTableState $state = null;

    public static function reset(): void
    {
        self::$state = null;
    }

    public static function loseClaim(string $metadataKey): string
    {
        if (self::$state === null) {
            throw new RuntimeException('Interval lost-claim probe state was not configured.');
        }

        $row = self::$state->table()->get($metadataKey);
        $metadata = unserialize($row['value']);
        $metadata['refreshingAt'] = 123.456789;

        self::$state->table()->set($metadataKey, [
            'value' => serialize($metadata),
            'expiration' => $row['expiration'],
        ]);

        return 'stale';
    }
}

class IntervalReentryProbe
{
    public static int $attempts = 0;

    public static ?SwooleStore $refresherStore = null;

    public static function reset(): void
    {
        self::$attempts = 0;
        self::$refresherStore = null;
    }
}
