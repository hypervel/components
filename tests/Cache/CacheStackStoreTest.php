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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Support\defer;

class CacheStackStoreTest extends TestCase
{
    use VerifiesDoubles;

    private SwooleStore&DoubleInterface $swoole;

    private RedisStore&DoubleInterface $redis;

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

        $this->swoole->expects('get')->with($key)->returns(null);
        $this->redis->expects('get')->with($key)->returns($record);
        $this->swoole->expects('put')->with($key, $record, $ttl)->returns(true);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testAuthoritativeReadUsesBottomLayerWithoutRepairingUpperLayer(): void
    {
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $bottom->expects('get')->with('key')->returns(['value' => 'fresh']);
        $top->expects('get')->never();
        $top->expects('put')->never();
        $top->expects('forever')->never();

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
        $store = Double::for(ArrayStore::class);
        $store->expects('get')->with('key')->returns($record);

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
        $outerTop = Double::for(ArrayStore::class);
        $innerTop = Double::for(ArrayStore::class);
        $innerBottom = Double::for(ArrayStore::class);
        $innerBottom->expects('get')->with('key')->returns([
            'value' => ['value' => 'fresh'],
        ]);
        $outerTop->expects('get')->never();
        $innerTop->expects('get')->never();

        $store = new StackStore([
            $outerTop,
            new StackStore([$innerTop, $innerBottom]),
        ]);

        $this->assertSame('fresh', $store->getAuthoritativeRaw('key'));
    }

    public function testAuthoritativeReadRecursesThroughBottomFailoverStore(): void
    {
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $bottom->expects('get')->with('key')->returns(['value' => 'fresh']);
        $top->expects('get')->never();

        $cache = Double::for(CacheManager::class);
        $cache->expects('store')->with('bottom')->returns(new Repository($bottom));
        $failover = new FailoverStore($cache, Double::for(Dispatcher::class), ['bottom']);

        $this->assertSame('fresh', (new StackStore([$top, $failover]))->getAuthoritativeRaw('key'));
    }

    public function testReadReturnsLowerValueWhenUpperRepairFails(): void
    {
        $this->createStores();

        $record = ['value' => 'bar'];

        $this->swoole->expects('get')->with('foo')->returns(null);
        $this->redis->expects('get')->with('foo')->returns($record);
        $this->swoole->expects('forever')->with('foo', $record)->returns(false);

        $this->assertSame('bar', $this->store->get('foo'));
    }

    public function testMalformedLowerRecordRemainsAMiss(): void
    {
        $this->createStores();

        $this->swoole->expects('get')->with('foo')->returns(null);
        $this->redis->expects('get')->with('foo')->returns(['expiration' => 123]);

        $this->assertNull($this->store->get('foo'));
    }

    public function testReadRepairExceptionPropagates(): void
    {
        $this->createStores();

        $exception = new RuntimeException('repair failed');
        $record = ['value' => 'bar'];

        $this->swoole->expects('get')->with('foo')->returns(null);
        $this->redis->expects('get')->with('foo')->returns($record);
        $this->swoole->expects('forever')->with('foo', $record)->throws($exception);

        try {
            $this->store->get('foo');
            $this->fail('Expected the read repair exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testThreeLayerReadContinuesRepairAfterInnerFailure(): void
    {
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $record = ['value' => 'bar'];

        $top->expects('get')->with('foo')->returns(null);
        $middle->expects('get')->with('foo')->returns(null);
        $bottom->expects('get')->with('foo')->returns($record);
        $middle->expects('forever')->with('foo', $record)->returns(false);
        $top->expects('forever')->with('foo', $record)->returns(true);

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

        $this->swoole->expects('get')->with($key)->returns(null);
        $this->redis->expects('get')->with($key)->returns($record);
        $this->swoole->expects('put')->with($key, $record, $ttl - 50)->returns(true);

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

        $this->swoole->expects('get')->with($key)->returns($record);

        $this->assertSame($value, $this->store->get($key));

        $this->redis->unused();
    }

    public function testMissingItemsReturnNull(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->expects('get')->with($key)->returns(null);
        $this->redis->expects('get')->with($key)->returns(null);

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

        $this->swoole->expects('put')->with($key, $record, $ttl)->returns(true);
        $this->redis->expects('put')->with($key, $record, $ttl)->returns(true);

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

        $this->swoole->expects('put')->with($key, $record, $ttl)->returns(false);

        $this->assertFalse($this->store->put($key, $value, $ttl));

        $this->redis->unused();
    }

    public function testPutItemToStoreFailedAndRollback(): void
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->expects('put')->with($key, $record, $ttl)->returns(true);
        $this->redis->expects('put')->with($key, $record, $ttl)->returns(false);
        $this->swoole->expects('forget')->with($key)->returns(true);

        $this->assertFalse($this->store->put($key, $value, $ttl));
    }

    public function testPutCompensatesEarlierLayerWhenLowerLayerThrows(): void
    {
        $sequence = [];
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->expects('put')->returns(true);
        $middle->expects('put')->throws($exception);
        $bottom->expects('put')->never();
        $middle->expects('forget')->with('foo')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'middle';

            return true;
        });
        $top->expects('forget')->with('foo')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top';

            return true;
        });

        try {
            (new StackStore([$top, $middle, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the lower-layer exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertSame(['middle', 'top'], $sequence);
    }

    public function testPutCompensatesCompletedLayersInReverseOrder(): void
    {
        $sequence = [];
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);

        $top->expects('put')->returns(true);
        $middle->expects('put')->returns(true);
        $bottom->expects('put')->returns(false);
        $middle->expects('forget')->with('foo')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'middle';

            return true;
        });
        $top->expects('forget')->with('foo')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top';

            return true;
        });

        $this->assertFalse((new StackStore([$top, $middle, $bottom]))->put('foo', 'bar', 60));

        $this->assertSame(['middle', 'top'], $sequence);
    }

    public function testPutPreservesFalseResultWhenCompensationThrows(): void
    {
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);

        $top->expects('put')->returns(true);
        $bottom->expects('put')->returns(false);
        $top->expects('forget')->with('foo')->throws(new RuntimeException('cleanup failed'));

        $this->assertFalse((new StackStore([$top, $bottom]))->put('foo', 'bar', 60));
    }

    public function testPutPreservesWriteFailureWhenCompensationAlsoThrows(): void
    {
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->expects('put')->returns(true);
        $bottom->expects('put')->throws($exception);
        $bottom->expects('forget')->with('foo')->throws(new RuntimeException('current cleanup failed'));
        $top->expects('forget')->with('foo')->throws(new RuntimeException('cleanup failed'));

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the write failure to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPutCompensatesLayerWhoseWriteThrows(): void
    {
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->expects('put')->throws($exception);
        $top->expects('forget')->with('foo')->returns(true);
        $bottom->expects('put')->never();

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
        $sequence = [];
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $cancellation = new CanceledException('write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Write cleanup failed.', previous: $cancellation);
        }
        $cleanupCancellation = new CanceledException('cleanup canceled');

        $top->expects('put')->returns(true);
        $bottom->expects('put')->throws($cancellation);
        $bottom->expects('forget')->with('foo')->resolves(function () use (&$sequence, $cleanupCancellation): never {
            $sequence[] = 'bottom';

            throw $cleanupCancellation;
        });
        $top->expects('forget')->with('foo')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top';

            return true;
        });

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the write cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(['bottom', 'top'], $sequence);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutCleanupCancellationSupersedesOrdinaryWriteFailure(bool $cleanupThrows): void
    {
        $sequence = [];
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $cleanupCancellation = new CanceledException('cleanup canceled');
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('Release cleanup failed.', previous: $cleanupCancellation);
        }

        $top->expects('put')->returns(true);
        $bottom->expects('put')->throws(new RuntimeException('write failed'));
        $bottom->expects('forget')->with('foo')->resolves(function () use (&$sequence, $cleanupCancellation): never {
            $sequence[] = 'bottom';

            throw $cleanupCancellation;
        });
        $top->expects('forget')->with('foo')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top';

            return true;
        });

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the cleanup cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }

        $this->assertSame(['bottom', 'top'], $sequence);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPutCleanupCancellationSupersedesFalseWriteResult(bool $cleanupThrows): void
    {
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $cleanupCancellation = new CanceledException('cleanup canceled');
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('Release cleanup failed.', previous: $cleanupCancellation);
        }

        $top->expects('put')->returns(true);
        $bottom->expects('put')->returns(false);
        $top->expects('forget')->with('foo')->throws($cleanupCancellation);

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

        $this->swoole->expects('many')->with(['foo', 'bar'])->returns([
            'foo' => ['value' => 'bar'],
            'bar' => ['value' => 'baz'],
        ]);
        $this->redis->expects('many')->never();

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $this->store->many(['foo', 'bar']));
    }

    public function testManyReadsEachLayerOnceAndRestoresLowerHitsWithBatchedWrites(): void
    {
        $sequence = [];
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $expiration = CarbonImmutable::now()->getTimestamp() + 100;
        $shorterExpiration = CarbonImmutable::now()->getTimestamp() + 50;
        $middleRecord = ['value' => 'middle', 'expiration' => $expiration];
        $bottomRecord = ['value' => 'bottom', 'expiration' => $expiration];
        $shorterRecord = ['value' => 'shorter', 'expiration' => $shorterExpiration];
        $foreverRecord = ['value' => 'forever'];

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));

        $top->expects('many')->with(['top', 'middle', 'bottom', 'shorter', 'forever', 'missing', 'malformed'])->returns([
            'top' => ['value' => 'top'],
            'middle' => null,
            'bottom' => null,
            'shorter' => null,
            'forever' => null,
            'missing' => null,
            'malformed' => null,
        ]);
        $middle->expects('many')->with(['middle', 'bottom', 'shorter', 'forever', 'missing', 'malformed'])->returns([
            'middle' => $middleRecord,
            'bottom' => null,
            'shorter' => null,
            'forever' => null,
            'missing' => null,
            'malformed' => ['expiration' => 123],
        ]);
        $bottom->expects('many')->with(['bottom', 'shorter', 'forever', 'missing'])->returns([
            'bottom' => $bottomRecord,
            'shorter' => $shorterRecord,
            'forever' => $foreverRecord,
            'missing' => null,
        ]);

        $middle->expects('putMany')->with(['bottom' => $bottomRecord], 70)->resolves(function () use (&$sequence): bool {
            $sequence[] = 'middle';

            return true;
        });
        $middle->expects('putMany')->with(['shorter' => $shorterRecord], 20)->returns(true);
        $middle->expects('forever')->with('forever', $foreverRecord)->returns(true);
        $top->expects('putMany')->with(['middle' => $middleRecord, 'bottom' => $bottomRecord], 70)->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top';

            return true;
        });
        $top->expects('putMany')->with(['shorter' => $shorterRecord], 20)->returns(true);
        $top->expects('forever')->with('forever', $foreverRecord)->returns(true);

        $this->assertSame([
            'top' => 'top',
            'middle' => 'middle',
            'bottom' => 'bottom',
            'shorter' => 'shorter',
            'forever' => 'forever',
            'missing' => null,
            'malformed' => null,
        ], (new StackStore([$top, $middle, $bottom]))->many(['top', 'middle', 'bottom', 'shorter', 'forever', 'missing', 'malformed']));

        $this->assertSame(['middle', 'top'], $sequence);
    }

    public function testManyMeasuresRemainingTtlBeforeEachRestoreAndSkipsRecordsThatExpired(): void
    {
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $expiringRecord = ['value' => 'expiring', 'expiration' => CarbonImmutable::now()->getTimestamp() + 1];
        $bottomRecord = ['value' => 'bottom', 'expiration' => CarbonImmutable::now()->getTimestamp() + 100];
        $advance = static function (int $seconds, mixed $result): Closure {
            return static function () use ($seconds, $result): mixed {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));

                return $result;
            };
        };

        $top->expects('many')->with(['expiring', 'bottom'])->returns(['expiring' => null, 'bottom' => null]);
        $middle->expects('many')->with(['expiring', 'bottom'])->returns(['expiring' => $expiringRecord, 'bottom' => null]);
        $bottom->expects('many')->with(['bottom'])->resolves($advance(5, ['bottom' => $bottomRecord]));
        $middle->expects('putMany')->with(['bottom' => $bottomRecord], 95)->resolves($advance(10, true));
        $top->expects('putMany')->with(['bottom' => $bottomRecord], 85)->returns(true);
        $top->expects('forever')->never();

        $this->assertSame(
            ['expiring' => 'expiring', 'bottom' => 'bottom'],
            (new StackStore([$top, $middle, $bottom]))->many(['expiring', 'bottom'])
        );
    }

    public function testReadForgetsUpperLayerInsteadOfRestoringRecordThatExpiredDuringLowerRead(): void
    {
        $this->createStores();

        $record = ['value' => 'bar', 'expiration' => CarbonImmutable::now()->getTimestamp() + 1];

        $this->swoole->expects('get')->with('foo')->returns(null);
        $this->redis->expects('get')->with('foo')->resolves(function () use ($record): array {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

            return $record;
        });
        $this->swoole->expects('forget')->with('foo')->returns(true);
        $this->swoole->expects('put')->never();

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

        $this->swoole->expects('putMany')->with($records, $ttl)->returns(true);
        $this->redis->expects('putMany')->with($records, $ttl)->returns(true);

        $this->assertTrue($this->store->putMany(['foo' => 'bar', 'bar' => 'baz'], $ttl));
    }

    public function testPutManyStartsEachLayersExpirationWhenThatLayerIsWritten(): void
    {
        $this->createStores();

        $ttl = 100;
        $now = CarbonImmutable::now()->getTimestamp();

        $this->swoole->expects('putMany')->with(['foo' => ['value' => 'bar', 'expiration' => $now + $ttl]], $ttl)->resolves(function (): bool {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));

            return true;
        });
        $this->redis->expects('putMany')->with(['foo' => ['value' => 'bar', 'expiration' => $now + 5 + $ttl]], $ttl)->returns(true);

        $this->assertTrue($this->store->putMany(['foo' => 'bar'], $ttl));
    }

    public function testPutManyReturnsTrueForEmptyInput(): void
    {
        $this->createStores();

        $this->assertTrue($this->store->putMany([], 100));

        $this->swoole->unused();
        $this->redis->unused();
    }

    public function testPutManyFailureForgetsTheBatchFromTheFailedAndCompletedLayersDeepestFirst(): void
    {
        $sequence = [];
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);

        $top->expects('putMany')->returns(true);
        $middle->expects('putMany')->returns(false);
        $bottom->expects('putMany')->never();
        $middle->expects('forget')->with('first')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'middle:first';

            return true;
        });
        $middle->expects('forget')->with('second')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'middle:second';

            return true;
        });
        $top->expects('forget')->with('first')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top:first';

            return true;
        });
        $top->expects('forget')->with('second')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top:second';

            return true;
        });

        $this->assertFalse((new StackStore([$top, $middle, $bottom]))->putMany(['first' => 'one', 'second' => 'two'], 60));

        $this->assertSame(['middle:first', 'middle:second', 'top:first', 'top:second'], $sequence);
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
        $sequence = [];
        $top = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $cleanupCancellation = new CanceledException('cleanup canceled');
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('Release cleanup failed.', previous: $cleanupCancellation);
        }

        $top->expects('putMany')->returns(true);
        $bottom->expects('putMany')->throws(new RuntimeException('write failed'));
        $bottom->expects('forget')->with('first')->resolves(function () use (&$sequence, $cleanupCancellation): never {
            $sequence[] = 'bottom:first';

            throw $cleanupCancellation;
        });
        $bottom->expects('forget')->with('second')->resolves(function () use (&$sequence): never {
            $sequence[] = 'bottom:second';

            throw new RuntimeException('cleanup failed');
        });
        $bottom->expects('forget')->never()->with('third');
        $top->expects('forget')->with('first')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top:first';

            return true;
        });
        $top->expects('forget')->with('second')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top:second';

            return true;
        });
        $top->expects('forget')->with('third')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'top:third';

            return true;
        });

        try {
            (new StackStore([$top, $bottom]))->putMany(['first' => 'one', 'second' => 'two', 'third' => 'three'], 60);
            $this->fail('Expected the cleanup cancellation to be thrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }

        $this->assertSame(['bottom:first', 'bottom:second', 'top:first', 'top:second', 'top:third'], $sequence);
    }

    #[DataProvider('proxyPutManyTtlProvider')]
    public function testProxyPutManyAppliesLayerTtl(?int $layerTtl, int $requestedTtl, int $expectedTtl): void
    {
        $store = Double::for(ArrayStore::class);
        $store->expects('putMany')->with(['key' => 'value'], $expectedTtl)->returns(true);

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
        $store = Double::for(ArrayStore::class);
        $store->expects('putMany')->with(['key' => 'value'], 60)->returns(false);

        $this->assertFalse((new StackStoreProxy($store, 60))->putMany(['key' => 'value'], 90));
    }

    public function testIncrement(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->expects('get')->times(2)->with($key)->returns(null, ['value' => 1]);
        $this->redis->expects('get')->with($key)->returns(null);
        $this->swoole->expects('forever')->with($key, ['value' => 1])->returns(true);
        $this->redis->expects('forever')->with($key, ['value' => 1])->returns(true);
        $this->swoole->expects('forever')->with($key, ['value' => 3])->returns(true);
        $this->redis->expects('forever')->with($key, ['value' => 3])->returns(true);

        $this->assertSame(1, $this->store->increment($key));
        $this->assertSame(3, $this->store->increment($key, 2));
    }

    public function testIncrementWithTTL(): void
    {
        $this->createStores();

        $key = 'foo';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

        $this->swoole->expects('get')->with($key)->returns(['value' => 1, 'expiration' => $expiration]);
        $this->swoole->expects('put')->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->returns(true);
        $this->redis->expects('put')->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->returns(true);

        $this->assertSame(2, $this->store->increment($key));
    }

    public function testIncrementDoesNotTreatFailedRepairAsMissingAndPreservesLowerValue(): void
    {
        $this->createStores();

        $record = ['value' => 5];
        $incrementedRecord = ['value' => 6];

        $this->swoole->expects('get')->times(2)->with('counter')->returns(null);
        $this->redis->expects('get')->times(2)->with('counter')->returns($record);
        $this->swoole->expects('forever')->times(2)->with('counter', $record)->returns(false);

        $this->swoole->expects('forever')->with('counter', $incrementedRecord)->returns(true);
        $this->redis->expects('forever')->with('counter', $incrementedRecord)->returns(false);
        $this->swoole->expects('forget')->with('counter')->returns(true);

        $this->assertFalse($this->store->increment('counter'));
        $this->assertSame(5, $this->store->get('counter'));
    }

    public function testDecrement(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->expects('get')->times(2)->with($key)->returns(null, ['value' => -1]);
        $this->redis->expects('get')->with($key)->returns(null);
        $this->swoole->expects('forever')->with($key, ['value' => -1])->returns(true);
        $this->redis->expects('forever')->with($key, ['value' => -1])->returns(true);
        $this->swoole->expects('forever')->with($key, ['value' => -3])->returns(true);
        $this->redis->expects('forever')->with($key, ['value' => -3])->returns(true);

        $this->assertSame(-1, $this->store->decrement($key));
        $this->assertSame(-3, $this->store->decrement($key, 2));
    }

    public function testDecrementWithTTL(): void
    {
        $this->createStores();

        $key = 'foo';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

        $this->swoole->expects('get')->with($key)->returns(['value' => 2, 'expiration' => $expiration]);
        $this->swoole->expects('put')->with($key, ['value' => 1, 'expiration' => $expiration], $ttl)->returns(true);
        $this->redis->expects('put')->with($key, ['value' => 1, 'expiration' => $expiration], $ttl)->returns(true);

        $this->assertSame(1, $this->store->decrement($key));
    }

    public function testForever(): void
    {
        $this->createStores();

        $this->swoole->expects('forever')->with('foo', ['value' => 'bar'])->returns(true);
        $this->redis->expects('forever')->with('foo', ['value' => 'bar'])->returns(true);

        $this->assertTrue($this->store->forever('foo', 'bar'));
    }

    public function testForeverFailed(): void
    {
        $this->createStores();

        $this->swoole->expects('forever')->with('foo', ['value' => 'bar'])->returns(false);

        $this->assertFalse($this->store->forever('foo', 'bar'));

        $this->redis->unused();
    }

    public function testForeverFailedWithRollback(): void
    {
        $this->createStores();

        $this->swoole->expects('forever')->with('foo', ['value' => 'bar'])->returns(true);
        $this->redis->expects('forever')->with('foo', ['value' => 'bar'])->returns(false);
        $this->swoole->expects('forget')->with('foo')->returns(true);

        $this->assertFalse($this->store->forever('foo', 'bar'));
    }

    public function testForget(): void
    {
        $this->createStores();

        $this->swoole->expects('forget')->with('foo')->returns(true);
        $this->redis->expects('forget')->with('foo')->returns(true);

        $this->assertTrue($this->store->forget('foo'));
    }

    public function testForgetFailed(): void
    {
        $this->createStores();

        $this->swoole->expects('forget')->with('foo')->returns(false);
        $this->redis->expects('forget')->with('foo')->returns(true);

        $this->assertFalse($this->store->forget('foo'));
    }

    public function testForgetAttemptsEveryLayerAndPreservesTheEarliestFailure(): void
    {
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $exception = new RuntimeException('first failure');

        $top->expects('forget')->with('foo')->throws($exception);
        $middle->expects('forget')->with('foo')->returns(false);
        $bottom->expects('forget')->with('foo')->throws(new RuntimeException('second failure'));

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
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $cancellation = new CanceledException('forget canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Forget cleanup failed.', previous: $cancellation);
        }

        $top->expects('forget')->with('foo')->throws(new RuntimeException('first failure'));
        $middle->expects('forget')->with('foo')->throws($cancellation);
        $bottom->expects('forget')->never();

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

        $this->swoole->expects('flush')->with(Argument::none())->returns(true);
        $this->redis->expects('flush')->with(Argument::none())->returns(true);

        $this->assertTrue($this->store->flush());
    }

    public function testFlushFailed(): void
    {
        $this->createStores();

        $this->swoole->expects('flush')->with(Argument::none())->returns(false);
        $this->redis->expects('flush')->with(Argument::none())->returns(true);

        $this->assertFalse($this->store->flush());
    }

    public function testFlushAttemptsEveryLayerAndPreservesTheEarliestFailure(): void
    {
        $top = Double::for(ArrayStore::class);
        $middle = Double::for(ArrayStore::class);
        $bottom = Double::for(ArrayStore::class);
        $exception = new RuntimeException('first failure');

        $top->expects('flush')->with(Argument::none())->throws($exception);
        $middle->expects('flush')->with(Argument::none())->returns(false);
        $bottom->expects('flush')->with(Argument::none())->throws(new RuntimeException('second failure'));

        try {
            (new StackStore([$top, $middle, $bottom]))->flush();
            $this->fail('Expected the first flush failure to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testThreeStores(): void
    {
        $array = Double::for(ArrayStore::class);
        $swoole = Double::for(SwooleStore::class);
        $redis = Double::for(RedisStore::class);

        $store = new StackStore([$array, $swoole, $redis]);

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $array->expects('get')->times(4)->with($key)->returns($record, null, null, null);
        $swoole->expects('get')->times(3)->with($key)->returns($record, null, null);
        $redis->expects('get')->times(2)->with($key)->returns($record, null);
        $array->expects('put')->times(4)->with($key, $record, $ttl)->returns(true);
        $swoole->expects('put')->times(3)->with($key, $record, $ttl)->returns(true);
        $redis->expects('put')->times(2)->with($key, $record, $ttl)->returns(true, false);
        $swoole->expects('forget')->with($key)->returns(true);
        $array->expects('forget')->with($key)->returns(true);

        $this->assertSame($value, $store->get($key));

        $this->assertSame($value, $store->get($key));

        $this->assertSame($value, $store->get($key));

        $this->assertNull($store->get($key));

        $this->assertTrue($store->put($key, $value, $ttl));

        $this->assertFalse($store->put($key, $value, $ttl));
    }

    public function testInvalidRecord(): void
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->expects('get')->times(2)->with($key)->returns('invalid record', null);
        $this->assertNull($this->store->get($key));

        $this->redis->expects('get')->with($key)->returns('invalid record');
        $this->assertNull($this->store->get($key));
    }

    public function testProxyMaxTTL(): void
    {
        $swoole = Double::for(SwooleStore::class);
        $redis = Double::for(RedisStore::class);

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

        $swoole->expects('put')->with($key, $record, $maxTTL)->returns(true);
        $redis->expects('put')->with($key, $record, $ttl)->returns(true);

        $this->assertTrue($store->put($key, $value, $ttl));
    }

    public function testProxyMaxTTLWithForever(): void
    {
        $swoole = Double::for(SwooleStore::class);
        $redis = Double::for(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $maxTTL = 3;
        $record = compact('value');

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->expects('put')->with($key, $record, $maxTTL)->returns(true);
        $redis->expects('forever')->with($key, $record)->returns(true);

        $this->assertTrue($store->forever($key, $value));
    }

    public function testTouchPropagatesThroughAllLayers(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $record = ['value' => $value, 'ttl' => 30];

        $this->swoole->expects('get')->with($key)->returns($record);
        $this->swoole->expects('put')->with($key, Argument::satisfies(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->returns(true);
        $this->redis->expects('put')->with($key, Argument::satisfies(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->returns(true);

        $this->assertTrue($this->store->touch($key, 60));
    }

    public function testTouchReturnsFalseWhenKeyDoesNotExist(): void
    {
        $this->createStores();

        $this->swoole->expects('get')->with('nonexistent')->returns(null);
        $this->redis->expects('get')->with('nonexistent')->returns(null);

        $this->assertFalse($this->store->touch('nonexistent', 60));
    }

    public function testTouchProxyCapsMaxTTL(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $swoole = Double::for(SwooleStore::class);
        $redis = Double::for(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $maxTTL = 3;
        $record = ['value' => $value, 'ttl' => 30];

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->expects('get')->with($key)->returns($record);
        $swoole->expects('put')->with($key, Argument::satisfies(fn ($r) => $r['value'] === $value && isset($r['expiration'])), $maxTTL)->returns(true);
        $redis->expects('put')->with($key, Argument::satisfies(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->returns(true);

        $this->assertTrue($store->touch($key, 60));
    }

    /**
     * Create a stack with isolated cache layer doubles.
     */
    private function createStores(): void
    {
        $this->redis = Double::for(RedisStore::class);
        $this->swoole = Double::for(SwooleStore::class);
        $this->store = new StackStore([$this->swoole, $this->redis]);
    }
}
