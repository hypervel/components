<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Closure;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Cache\SwooleStore;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Support\defer;

class CacheStackStoreTest extends TestCase
{
    /** @var MockInterface|SwooleStore */
    private SwooleStore $swoole;

    /** @var MockInterface|RedisStore */
    private RedisStore $redis;

    private StackStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2000-01-01 12:34:56.123456');
    }

    public function testRetrieveItemFromStoreStacked(): void
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testAuthoritativeReadUsesBottomLayerWithoutRepairingUpperLayer(): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $bottom->shouldReceive('get')->once()->with('key')->andReturn(['value' => 'fresh']);
        $top->shouldNotReceive('get');
        $top->shouldNotReceive('put');
        $top->shouldNotReceive('forever');

        $store = new StackStore([$top, $bottom]);

        $this->assertSame('fresh', $store->getAuthoritativeRaw('key'));
    }

    public function testFlexibleSkipsRefreshWhenBottomMarkerIsNewerThanUpperLayer(): void
    {
        $top = new ArrayStore;
        $bottom = new ArrayStore;
        $repository = new Repository(new StackStore([$top, $bottom]));
        $markerKey = Repository::FLEXIBLE_CREATED_KEY_PREFIX . 'key';
        $expiration = CarbonImmutable::now()->addSeconds(20)->getTimestamp();
        $topValue = ['value' => 'stale', 'expiration' => $expiration];
        $bottomValue = ['value' => 'fresh', 'expiration' => $expiration];
        $topMarker = ['value' => CarbonImmutable::now()->subSeconds(11)->getTimestamp(), 'expiration' => $expiration];
        $bottomMarker = ['value' => CarbonImmutable::now()->getTimestamp(), 'expiration' => $expiration];

        $top->put('key', $topValue, 20);
        $top->put($markerKey, $topMarker, 20);
        $bottom->put('key', $bottomValue, 20);
        $bottom->put($markerKey, $bottomMarker, 20);

        $callbackInvocations = 0;

        $this->assertSame('stale', $repository->flexible('key', [10, 20], function () use (&$callbackInvocations): string {
            ++$callbackInvocations;

            return 'refreshed';
        }));

        defer()->invoke();

        $this->assertSame(0, $callbackInvocations);
        $this->assertSame($topValue, $top->get('key'));
        $this->assertSame($bottomValue, $bottom->get('key'));
        $this->assertSame($bottomMarker, $bottom->get($markerKey));
    }

    #[DataProvider('authoritativeRecordProvider')]
    public function testAuthoritativeReadHandlesSingleLayerRecords(mixed $record, mixed $expected): void
    {
        $store = m::mock(ArrayStore::class);
        $store->shouldReceive('get')->once()->with('key')->andReturn($record);

        $this->assertSame($expected, (new StackStore([$store]))->getAuthoritativeRaw('key'));
    }

    public static function authoritativeRecordProvider(): array
    {
        return [
            'value' => [['value' => 'fresh'], 'fresh'],
            'null sentinel' => [['value' => NullSentinel::VALUE], NullSentinel::VALUE],
            'missing' => [null, null],
            'scalar' => ['invalid', null],
            'malformed array' => [['expiration' => 123], null],
        ];
    }

    public function testAuthoritativeReadRecursesThroughBottomStack(): void
    {
        $outerTop = m::mock(ArrayStore::class);
        $innerTop = m::mock(ArrayStore::class);
        $innerBottom = m::mock(ArrayStore::class);
        $innerBottom->shouldReceive('get')->once()->with('key')->andReturn([
            'value' => ['value' => 'fresh'],
        ]);
        $outerTop->shouldNotReceive('get');
        $innerTop->shouldNotReceive('get');

        $store = new StackStore([
            $outerTop,
            new StackStore([$innerTop, $innerBottom]),
        ]);

        $this->assertSame('fresh', $store->getAuthoritativeRaw('key'));
    }

    public function testAuthoritativeReadRecursesThroughBottomFailoverStore(): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $bottom->shouldReceive('get')->once()->with('key')->andReturn(['value' => 'fresh']);
        $top->shouldNotReceive('get');

        $cache = m::mock(CacheManager::class);
        $cache->shouldReceive('store')->once()->with('bottom')->andReturn(new Repository($bottom));
        $failover = new FailoverStore($cache, m::mock(Dispatcher::class), ['bottom']);

        $this->assertSame('fresh', (new StackStore([$top, $failover]))->getAuthoritativeRaw('key'));
    }

    public function testReadReturnsLowerValueWhenUpperRepairFails(): void
    {
        $this->createStores();

        $record = ['value' => 'bar'];

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $this->redis->shouldReceive('get')->once()->with('foo')->andReturn($record);
        $this->swoole->shouldReceive('forever')->once()->with('foo', $record)->andReturnFalse();

        $this->assertSame('bar', $this->store->get('foo'));
    }

    public function testMalformedLowerRecordRemainsAMiss(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $this->redis->shouldReceive('get')->once()->with('foo')->andReturn(['expiration' => 123]);

        $this->assertNull($this->store->get('foo'));
    }

    public function testReadRepairExceptionPropagates(): void
    {
        $this->createStores();

        $exception = new RuntimeException('repair failed');
        $record = ['value' => 'bar'];

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $this->redis->shouldReceive('get')->once()->with('foo')->andReturn($record);
        $this->swoole->shouldReceive('forever')->once()->with('foo', $record)->andThrow($exception);

        try {
            $this->store->get('foo');
            $this->fail('Expected the read repair exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testThreeLayerReadContinuesRepairAfterInnerFailure(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $record = ['value' => 'bar'];

        $top->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $middle->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $bottom->shouldReceive('get')->once()->with('foo')->andReturn($record);
        $middle->shouldReceive('forever')->once()->with('foo', $record)->andReturnFalse();
        $top->shouldReceive('forever')->once()->with('foo', $record)->andReturnTrue();

        $this->assertSame('bar', (new StackStore([$top, $middle, $bottom]))->get('foo'));
    }

    public function testConstructorRequiresAtLeastOneStore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('A cache stack requires at least one store layer.');

        new StackStore([]);
    }

    public function testPutWithCorrectTTL(): void
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(50));

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl - 50)->andReturn(true);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testAvoidRedundantCall(): void
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn($record);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testMissingItemsReturnNull(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn(null);

        $this->assertNull($this->store->get($key));
    }

    public function testNullSentinelPropagatesThroughStackedStores(): void
    {
        $stack = new StackStore([
            new ArrayStore(serializesValues: true),
            new ArrayStore(serializesValues: true),
        ]);
        $repo = new Repository($stack);

        $result1 = $repo->rememberNullable('k', 60, fn () => null);
        $this->assertNull($result1);

        // Stack-level get unwraps the record and returns the stored sentinel.
        $this->assertSame(NullSentinel::VALUE, $stack->get('k'));

        // Second remember call: callback must not re-run — sentinel recognized as hit.
        $invoked = false;
        $result2 = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result2);
        $this->assertFalse($invoked);
    }

    public function testPutItemToStoreStacked(): void
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);

        $this->assertTrue($this->store->put($key, $value, $ttl));
    }

    public function testPutItemToStoreFailed(): void
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(false);

        $this->assertFalse($this->store->put($key, $value, $ttl));
    }

    public function testPutItemToStoreFailedAndRollback(): void
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(false);
        $this->swoole->shouldReceive('forget')->once()->with($key)->andReturn(true);

        $this->assertFalse($this->store->put($key, $value, $ttl));
    }

    public function testPutCompensatesEarlierLayerWhenLowerLayerThrows(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->shouldReceive('put')->once()->andReturnTrue();
        $middle->shouldReceive('put')->once()->andThrow($exception);
        $bottom->shouldNotReceive('put');
        $middle->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andReturnTrue();
        $top->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andReturnTrue();

        try {
            (new StackStore([$top, $middle, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the lower-layer exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPutCompensatesCompletedLayersInReverseOrder(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);

        $top->shouldReceive('put')->once()->andReturnTrue();
        $middle->shouldReceive('put')->once()->andReturnTrue();
        $bottom->shouldReceive('put')->once()->andReturnFalse();
        $middle->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andReturnTrue();
        $top->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andReturnTrue();

        $this->assertFalse((new StackStore([$top, $middle, $bottom]))->put('foo', 'bar', 60));
    }

    public function testPutPreservesFalseResultWhenCompensationThrows(): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);

        $top->shouldReceive('put')->once()->andReturnTrue();
        $bottom->shouldReceive('put')->once()->andReturnFalse();
        $top->shouldReceive('forget')->once()->with('foo')->andThrow(new RuntimeException('cleanup failed'));

        $this->assertFalse((new StackStore([$top, $bottom]))->put('foo', 'bar', 60));
    }

    public function testPutPreservesWriteFailureWhenCompensationAlsoThrows(): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->shouldReceive('put')->once()->andReturnTrue();
        $bottom->shouldReceive('put')->once()->andThrow($exception);
        $bottom->shouldReceive('forget')->once()->with('foo')->andThrow(new RuntimeException('current cleanup failed'));
        $top->shouldReceive('forget')->once()->with('foo')->andThrow(new RuntimeException('cleanup failed'));

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the write failure to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPutCompensatesLayerWhoseWriteThrows(): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->shouldReceive('put')->once()->andThrow($exception);
        $top->shouldReceive('forget')->once()->with('foo')->andReturnTrue();
        $bottom->shouldNotReceive('put');

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the current-layer exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPutCompensatesCommittedWriteThatIsCanceled(): void
    {
        $cancellation = new CanceledException('write canceled');
        $top = new ArrayStore;
        $bottom = new class($cancellation) extends ArrayStore {
            public function __construct(private readonly CanceledException $cancellation)
            {
                parent::__construct();
            }

            /**
             * Store an item before simulating delivery of cancellation.
             */
            public function put(string $key, mixed $value, int $seconds): bool
            {
                parent::put($key, $value, $seconds);

                throw $this->cancellation;
            }
        };
        $stack = new StackStore([$top, $bottom]);

        try {
            $stack->put('foo', 'bar', 60);
            $this->fail('Expected the write cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertNull($top->get('foo'));
        $this->assertNull($bottom->get('foo'));
        $this->assertNull($stack->get('foo'));
    }

    public function testPutDoesNotCompensateFalseLayerAndPreservesItsExistingValue(): void
    {
        $top = new class extends ArrayStore {
            public bool $rejectWrites = false;

            /**
             * Store an item unless writes are rejected.
             */
            public function put(string $key, mixed $value, int $seconds): bool
            {
                return $this->rejectWrites
                    ? false
                    : parent::put($key, $value, $seconds);
            }
        };

        $top->put('foo', ['value' => 'old'], 60);
        $top->rejectWrites = true;
        $stack = new StackStore([$top, new ArrayStore]);

        $this->assertFalse($stack->put('foo', 'new', 60));
        $this->assertSame('old', $stack->get('foo'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutPreservesOriginalCancellationAndCompletesCompensation(bool $cleanupThrows): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $cancellation = new CanceledException('write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Write cleanup failed.', previous: $cancellation);
        }
        $cleanupCancellation = new CanceledException('cleanup canceled');

        $top->shouldReceive('put')->once()->andReturnTrue();
        $bottom->shouldReceive('put')->once()->andThrow($cancellation);
        $bottom->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andThrow($cleanupCancellation);
        $top->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andReturnTrue();

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the write cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutCleanupCancellationSupersedesOrdinaryWriteFailure(bool $cleanupThrows): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $cleanupCancellation = new CanceledException('cleanup canceled');
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('Release cleanup failed.', previous: $cleanupCancellation);
        }

        $top->shouldReceive('put')->once()->andReturnTrue();
        $bottom->shouldReceive('put')->once()->andThrow(new RuntimeException('write failed'));
        $bottom->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andThrow($cleanupCancellation);
        $top->shouldReceive('forget')->once()->with('foo')->globally()->ordered()->andReturnTrue();

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the cleanup cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutCleanupCancellationSupersedesFalseWriteResult(bool $cleanupThrows): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $cleanupCancellation = new CanceledException('cleanup canceled');
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('Release cleanup failed.', previous: $cleanupCancellation);
        }

        $top->shouldReceive('put')->once()->andReturnTrue();
        $bottom->shouldReceive('put')->once()->andReturnFalse();
        $top->shouldReceive('forget')->once()->with('foo')->andThrow($cleanupCancellation);

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the cleanup cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }
    }

    public function testMany(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('many')->once()->with(['foo', 'bar'])->andReturn([
            'foo' => ['value' => 'bar'],
            'bar' => ['value' => 'baz'],
        ]);
        $this->redis->shouldNotReceive('many');

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $this->store->many(['foo', 'bar']));
    }

    public function testManyReadsEachLayerOnceAndRestoresLowerHitsWithBatchedWrites(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $expiration = CarbonImmutable::now()->getTimestamp() + 100;
        $shorterExpiration = CarbonImmutable::now()->getTimestamp() + 50;
        $middleRecord = ['value' => 'middle', 'expiration' => $expiration];
        $bottomRecord = ['value' => 'bottom', 'expiration' => $expiration];
        $shorterRecord = ['value' => 'shorter', 'expiration' => $shorterExpiration];
        $foreverRecord = ['value' => 'forever'];

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));

        $top->shouldReceive('many')->once()->with(['top', 'middle', 'bottom', 'shorter', 'forever', 'missing', 'malformed'])->andReturn([
            'top' => ['value' => 'top'],
            'middle' => null,
            'bottom' => null,
            'shorter' => null,
            'forever' => null,
            'missing' => null,
            'malformed' => null,
        ]);
        $middle->shouldReceive('many')->once()->with(['middle', 'bottom', 'shorter', 'forever', 'missing', 'malformed'])->andReturn([
            'middle' => $middleRecord,
            'bottom' => null,
            'shorter' => null,
            'forever' => null,
            'missing' => null,
            'malformed' => ['expiration' => 123],
        ]);
        $bottom->shouldReceive('many')->once()->with(['bottom', 'shorter', 'forever', 'missing'])->andReturn([
            'bottom' => $bottomRecord,
            'shorter' => $shorterRecord,
            'forever' => $foreverRecord,
            'missing' => null,
        ]);

        $middle->shouldReceive('putMany')->once()->with(['bottom' => $bottomRecord], 70)->globally()->ordered()->andReturnTrue();
        $middle->shouldReceive('putMany')->once()->with(['shorter' => $shorterRecord], 20)->andReturnTrue();
        $middle->shouldReceive('forever')->once()->with('forever', $foreverRecord)->andReturnTrue();
        $top->shouldReceive('putMany')->once()->with(['middle' => $middleRecord, 'bottom' => $bottomRecord], 70)->globally()->ordered()->andReturnTrue();
        $top->shouldReceive('putMany')->once()->with(['shorter' => $shorterRecord], 20)->andReturnTrue();
        $top->shouldReceive('forever')->once()->with('forever', $foreverRecord)->andReturnTrue();

        $this->assertSame([
            'top' => 'top',
            'middle' => 'middle',
            'bottom' => 'bottom',
            'shorter' => 'shorter',
            'forever' => 'forever',
            'missing' => null,
            'malformed' => null,
        ], (new StackStore([$top, $middle, $bottom]))->many(['top', 'middle', 'bottom', 'shorter', 'forever', 'missing', 'malformed']));
    }

    public function testManyMeasuresRemainingTtlBeforeEachRestoreAndSkipsRecordsThatExpired(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $expiringRecord = ['value' => 'expiring', 'expiration' => CarbonImmutable::now()->getTimestamp() + 1];
        $bottomRecord = ['value' => 'bottom', 'expiration' => CarbonImmutable::now()->getTimestamp() + 100];
        $advance = static function (int $seconds, mixed $result): Closure {
            return static function () use ($seconds, $result): mixed {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));

                return $result;
            };
        };

        $top->shouldReceive('many')->once()->with(['expiring', 'bottom'])->andReturn(['expiring' => null, 'bottom' => null]);
        $middle->shouldReceive('many')->once()->with(['expiring', 'bottom'])->andReturn(['expiring' => $expiringRecord, 'bottom' => null]);
        $bottom->shouldReceive('many')->once()->with(['bottom'])->andReturnUsing($advance(5, ['bottom' => $bottomRecord]));
        $middle->shouldReceive('putMany')->once()->with(['bottom' => $bottomRecord], 95)->andReturnUsing($advance(10, true));
        $top->shouldReceive('putMany')->once()->with(['bottom' => $bottomRecord], 85)->andReturnTrue();
        $top->shouldNotReceive('forever');

        $this->assertSame(
            ['expiring' => 'expiring', 'bottom' => 'bottom'],
            (new StackStore([$top, $middle, $bottom]))->many(['expiring', 'bottom'])
        );
    }

    public function testReadForgetsUpperLayerInsteadOfRestoringRecordThatExpiredDuringLowerRead(): void
    {
        $this->createStores();

        $record = ['value' => 'bar', 'expiration' => CarbonImmutable::now()->getTimestamp() + 1];

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $this->redis->shouldReceive('get')->once()->with('foo')->andReturnUsing(function () use ($record): array {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

            return $record;
        });
        $this->swoole->shouldReceive('forget')->once()->with('foo')->andReturnTrue();
        $this->swoole->shouldNotReceive('put');

        $this->assertSame('bar', $this->store->get('foo'));
    }

    public function testPutMany(): void
    {
        $this->createStores();

        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $records = [
            'foo' => ['value' => 'bar', 'expiration' => $expiration],
            'bar' => ['value' => 'baz', 'expiration' => $expiration],
        ];

        $this->swoole->shouldReceive('putMany')->once()->with($records, $ttl)->andReturn(true);
        $this->redis->shouldReceive('putMany')->once()->with($records, $ttl)->andReturn(true);

        $this->assertTrue($this->store->putMany(['foo' => 'bar', 'bar' => 'baz'], $ttl));
    }

    public function testPutManyStartsEachLayersExpirationWhenThatLayerIsWritten(): void
    {
        $this->createStores();

        $ttl = 100;
        $now = CarbonImmutable::now()->getTimestamp();

        $this->swoole->shouldReceive('putMany')->once()->with(['foo' => ['value' => 'bar', 'expiration' => $now + $ttl]], $ttl)->andReturnUsing(function (): bool {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));

            return true;
        });
        $this->redis->shouldReceive('putMany')->once()->with(['foo' => ['value' => 'bar', 'expiration' => $now + 5 + $ttl]], $ttl)->andReturnTrue();

        $this->assertTrue($this->store->putMany(['foo' => 'bar'], $ttl));
    }

    public function testPutManyReturnsTrueForEmptyInput(): void
    {
        $this->createStores();

        $this->assertTrue($this->store->putMany([], 100));
    }

    public function testPutManyFailureForgetsTheBatchFromTheFailedAndCompletedLayersDeepestFirst(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);

        $top->shouldReceive('putMany')->once()->andReturnTrue();
        $middle->shouldReceive('putMany')->once()->andReturnFalse();
        $bottom->shouldNotReceive('putMany');
        $middle->shouldReceive('forget')->once()->with('first')->globally()->ordered()->andReturnTrue();
        $middle->shouldReceive('forget')->once()->with('second')->globally()->ordered()->andReturnTrue();
        $top->shouldReceive('forget')->once()->with('first')->globally()->ordered()->andReturnTrue();
        $top->shouldReceive('forget')->once()->with('second')->globally()->ordered()->andReturnTrue();

        $this->assertFalse((new StackStore([$top, $middle, $bottom]))->putMany(['first' => 'one', 'second' => 'two'], 60));
    }

    public function testPutManyForgetsKeysALayerStoredBeforeThrowing(): void
    {
        $exception = new RuntimeException('write failed');
        $top = new ArrayStore;
        $bottom = new class($exception) extends ArrayStore {
            public function __construct(private readonly RuntimeException $exception)
            {
                parent::__construct();
            }

            /**
             * Store the first item before simulating a failure.
             */
            public function putMany(array $values, int $seconds): bool
            {
                $this->put((string) array_key_first($values), reset($values), $seconds);

                throw $this->exception;
            }
        };
        $stack = new StackStore([$top, $bottom]);

        try {
            $stack->putMany(['first' => 'one', 'second' => 'two'], 60);
            $this->fail('Expected the write failure to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertSame([null, null], [$top->get('first'), $top->get('second')]);
        $this->assertSame([null, null], [$bottom->get('first'), $bottom->get('second')]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutManyCleanupContinuesAfterCancellationStopsAtOrdinaryFailureAndKeepsCancellation(bool $cleanupThrows): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $cleanupCancellation = new CanceledException('cleanup canceled');
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('Release cleanup failed.', previous: $cleanupCancellation);
        }

        $top->shouldReceive('putMany')->once()->andReturnTrue();
        $bottom->shouldReceive('putMany')->once()->andThrow(new RuntimeException('write failed'));
        $bottom->shouldReceive('forget')->once()->with('first')->globally()->ordered()->andThrow($cleanupCancellation);
        $bottom->shouldReceive('forget')->once()->with('second')->globally()->ordered()->andThrow(new RuntimeException('cleanup failed'));
        $bottom->shouldNotReceive('forget')->with('third');
        $top->shouldReceive('forget')->once()->with('first')->globally()->ordered()->andReturnTrue();
        $top->shouldReceive('forget')->once()->with('second')->globally()->ordered()->andReturnTrue();
        $top->shouldReceive('forget')->once()->with('third')->globally()->ordered()->andReturnTrue();

        try {
            (new StackStore([$top, $bottom]))->putMany(['first' => 'one', 'second' => 'two', 'third' => 'three'], 60);
            $this->fail('Expected the cleanup cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }
    }

    #[DataProvider('proxyPutManyTtlProvider')]
    public function testProxyPutManyAppliesLayerTtl(?int $layerTtl, int $requestedTtl, int $expectedTtl): void
    {
        $store = m::mock(ArrayStore::class);
        $store->shouldReceive('putMany')->once()->with(['key' => 'value'], $expectedTtl)->andReturnTrue();

        $proxy = new StackStoreProxy($store, $layerTtl);

        $this->assertTrue($proxy->putMany(['key' => 'value'], $requestedTtl));
    }

    public static function proxyPutManyTtlProvider(): array
    {
        return [
            'no cap' => [null, 90, 90],
            'shorter requested TTL' => [60, 30, 30],
            'equal requested TTL' => [60, 60, 60],
            'longer requested TTL' => [60, 90, 60],
        ];
    }

    public function testProxyPutManyReturnsUnderlyingResult(): void
    {
        $store = m::mock(ArrayStore::class);
        $store->shouldReceive('putMany')->once()->with(['key' => 'value'], 60)->andReturnFalse();

        $this->assertFalse((new StackStoreProxy($store, 60))->putMany(['key' => 'value'], 90));
    }

    public function testIncrement(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => 1])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => 1])->andReturn(true);
        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => 1]);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => 3])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => 3])->andReturn(true);

        $this->assertSame(1, $this->store->increment($key));
        $this->assertSame(3, $this->store->increment($key, 2));
    }

    public function testIncrementWithTTL(): void
    {
        $this->createStores();

        $key = 'foo';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => 1, 'expiration' => $expiration]);
        $this->swoole->shouldReceive('put')->once()->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->assertSame(2, $this->store->increment($key));
    }

    public function testIncrementDoesNotTreatFailedRepairAsMissingAndPreservesLowerValue(): void
    {
        $this->createStores();

        $record = ['value' => 5];
        $incrementedRecord = ['value' => 6];

        $this->swoole->shouldReceive('get')->twice()->with('counter')->andReturnNull();
        $this->redis->shouldReceive('get')->twice()->with('counter')->andReturn($record);
        $this->swoole->shouldReceive('forever')->twice()->with('counter', $record)->andReturnFalse();

        $this->swoole->shouldReceive('forever')->once()->with('counter', $incrementedRecord)->andReturnTrue();
        $this->redis->shouldReceive('forever')->once()->with('counter', $incrementedRecord)->andReturnFalse();
        $this->swoole->shouldReceive('forget')->once()->with('counter')->andReturnTrue();

        $this->assertFalse($this->store->increment('counter'));
        $this->assertSame(5, $this->store->get('counter'));
    }

    public function testDecrement(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => -1])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => -1])->andReturn(true);
        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => -1]);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => -3])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => -3])->andReturn(true);

        $this->assertSame(-1, $this->store->decrement($key));
        $this->assertSame(-3, $this->store->decrement($key, 2));
    }

    public function testDecrementWithTTL(): void
    {
        $this->createStores();

        $key = 'foo';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => 2, 'expiration' => $expiration]);
        $this->swoole->shouldReceive('put')->once()->with($key, ['value' => 1, 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, ['value' => 1, 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->assertSame(1, $this->store->decrement($key));
    }

    public function testForever(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(true);

        $this->assertTrue($this->store->forever('foo', 'bar'));
    }

    public function testForeverFailed(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(false);

        $this->assertFalse($this->store->forever('foo', 'bar'));
    }

    public function testForeverFailedWithRollback(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(false);
        $this->swoole->shouldReceive('forget')->once()->with('foo')->andReturn(true);

        $this->assertFalse($this->store->forever('foo', 'bar'));
    }

    public function testForget(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('forget')->once()->with('foo')->andReturn(true);
        $this->redis->shouldReceive('forget')->once()->with('foo')->andReturn(true);

        $this->assertTrue($this->store->forget('foo'));
    }

    public function testForgetFailed(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('forget')->once()->with('foo')->andReturn(false);
        $this->redis->shouldReceive('forget')->once()->with('foo')->andReturn(true);

        $this->assertFalse($this->store->forget('foo'));
    }

    public function testForgetAttemptsEveryLayerAndPreservesTheEarliestFailure(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $exception = new RuntimeException('first failure');

        $top->shouldReceive('forget')->once()->with('foo')->andThrow($exception);
        $middle->shouldReceive('forget')->once()->with('foo')->andReturnFalse();
        $bottom->shouldReceive('forget')->once()->with('foo')->andThrow(new RuntimeException('second failure'));

        try {
            (new StackStore([$top, $middle, $bottom]))->forget('foo');
            $this->fail('Expected the first forget failure to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testForgetCancellationSupersedesEarlierFailureAndStopsFurtherLayers(bool $cleanupThrows): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $cancellation = new CanceledException('forget canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Forget cleanup failed.', previous: $cancellation);
        }

        $top->shouldReceive('forget')->once()->with('foo')->andThrow(new RuntimeException('first failure'));
        $middle->shouldReceive('forget')->once()->with('foo')->andThrow($cancellation);
        $bottom->shouldNotReceive('forget');

        try {
            (new StackStore([$top, $middle, $bottom]))->forget('foo');
            $this->fail('Expected the forget cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testFlush(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('flush')->once()->withNoArgs()->andReturn(true);
        $this->redis->shouldReceive('flush')->once()->withNoArgs()->andReturn(true);

        $this->assertTrue($this->store->flush());
    }

    public function testFlushFailed(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('flush')->once()->withNoArgs()->andReturn(false);
        $this->redis->shouldReceive('flush')->once()->withNoArgs()->andReturn(true);

        $this->assertFalse($this->store->flush());
    }

    public function testFlushAttemptsEveryLayerAndPreservesTheEarliestFailure(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $exception = new RuntimeException('first failure');

        $top->shouldReceive('flush')->once()->withNoArgs()->andThrow($exception);
        $middle->shouldReceive('flush')->once()->withNoArgs()->andReturnFalse();
        $bottom->shouldReceive('flush')->once()->withNoArgs()->andThrow(new RuntimeException('second failure'));

        try {
            (new StackStore([$top, $middle, $bottom]))->flush();
            $this->fail('Expected the first flush failure to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testThreeStores(): void
    {
        /** @var ArrayStore|MockInterface $array */
        $array = m::mock(ArrayStore::class);
        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $store = new StackStore([$array, $swoole, $redis]);

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $array->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->assertSame($value, $store->get($key));

        $array->shouldReceive('get')->once()->with($key)->andReturn(null);
        $swoole->shouldReceive('get')->once()->with($key)->andReturn($record);
        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->assertSame($value, $store->get($key));

        $array->shouldReceive('get')->once()->with($key)->andReturn(null);
        $swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $redis->shouldReceive('get')->once()->with($key)->andReturn($record);
        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->assertSame($value, $store->get($key));

        $array->shouldReceive('get')->once()->with($key)->andReturn(null);
        $swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $redis->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->assertNull($store->get($key));

        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->assertTrue($store->put($key, $value, $ttl));

        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(false);
        $swoole->shouldReceive('forget')->once()->with($key)->andReturn(true);
        $array->shouldReceive('forget')->once()->with($key)->andReturn(true);
        $this->assertFalse($store->put($key, $value, $ttl));
    }

    public function testInvalidRecord(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn('invalid record');
        $this->assertNull($this->store->get($key));

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn('invalid record');
        $this->assertNull($this->store->get($key));
    }

    public function testProxyMaxTTL(): void
    {
        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $maxTTL = 3;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->shouldReceive('put')->once()->with($key, $record, $maxTTL)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);

        $this->assertTrue($store->put($key, $value, $ttl));
    }

    public function testProxyMaxTTLWithForever(): void
    {
        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $maxTTL = 3;
        $record = compact('value');

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->shouldReceive('put')->once()->with($key, $record, $maxTTL)->andReturn(true);
        $redis->shouldReceive('forever')->once()->with($key, $record)->andReturn(true);

        $this->assertTrue($store->forever($key, $value));
    }

    public function testTouchPropagatesThroughAllLayers(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $record = ['value' => $value, 'ttl' => 30];

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->swoole->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->andReturn(true);

        $this->assertTrue($this->store->touch($key, 60));
    }

    public function testTouchReturnsFalseWhenKeyDoesNotExist(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('get')->once()->with('nonexistent')->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with('nonexistent')->andReturn(null);

        $this->assertFalse($this->store->touch('nonexistent', 60));
    }

    public function testTouchProxyCapsMaxTTL(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $maxTTL = 3;
        $record = ['value' => $value, 'ttl' => 30];

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->shouldReceive('get')->once()->with($key)->andReturn($record);
        $swoole->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), $maxTTL)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->andReturn(true);

        $this->assertTrue($store->touch($key, 60));
    }

    private function createStores()
    {
        $this->redis = m::mock(RedisStore::class);
        $this->swoole = m::mock(SwooleStore::class);
        $this->store = new StackStore([$this->swoole, $this->redis]);
    }
}
