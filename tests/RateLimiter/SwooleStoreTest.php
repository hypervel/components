<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Config\Repository;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\Exceptions\SwooleTableFullException;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\Swoole\TableManager;
use Hypervel\RateLimiter\Swoole\TableState;
use Hypervel\RateLimiter\SwooleStore;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\RateLimiter\Fixtures\RateLimiterStoreContract;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

class SwooleStoreTest extends TestCase
{
    use RateLimiterStoreContract;

    public function testFixedWindowOperationsUseNumericState(): void
    {
        [$store, $state] = $this->store();
        $policy = Limit::perMinute(5)->cost(2);

        $first = $store->consume('fixed', $policy);
        $second = $store->consume('fixed', $policy);
        $denied = $store->consume('fixed', $policy);

        $this->assertTrue($first->allowed());
        $this->assertSame(3, $first->remaining());
        $this->assertSame(1, $second->remaining());
        $this->assertTrue($denied->denied());
        $this->assertSame(1, $denied->remaining());
        $this->assertSame(4, $state->table()->get('fixed', 'value'));
        $this->assertTrue($store->clear('fixed'));
        $this->assertFalse($store->clear('fixed'));
    }

    public function testInspectingMissingStateDoesNotCreateARow(): void
    {
        [$store, $state] = $this->store();

        $result = $store->inspect('missing', Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(10, $result->remaining());
        $this->assertSame(0, $result->resetAfter());
        $this->assertFalse($state->table()->exist('missing'));
    }

    public function testLeakyBucketAndBackoffUseTheSharedCalculator(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 00:00:00');
        [$store] = $this->store();

        $bucket = LeakyBucket::perSecond(2);
        $this->assertTrue($store->consume('bucket', $bucket)->allowed());
        $this->assertTrue($store->consume('bucket', $bucket)->allowed());
        $this->assertTrue($store->consume('bucket', $bucket)->denied());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(500));
        $this->assertTrue($store->consume('bucket', $bucket)->allowed());

        $backoff = Backoff::exponential(
            after: 2,
            initialDelay: 1,
            maxDelay: 4,
            resetAfter: 10,
        );
        $this->assertTrue($store->recordFailure('backoff', $backoff)->allowed());
        $this->assertTrue($store->recordFailure('backoff', $backoff)->denied());
        $this->assertTrue($store->inspect('backoff', $backoff)->denied());
        $this->assertTrue($store->clear('backoff'));
    }

    public function testSwitchingToTestTimeKeepsTheEpochClockScale(): void
    {
        [$store] = $this->store();
        $policy = Limit::perSecond(1);

        $this->assertTrue($store->consume('clock', $policy)->allowed());

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(time() + 2));

        $this->assertTrue($store->consume('clock', $policy)->allowed());
    }

    public function testPrunesExpiredRows(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 00:00:00');
        [$store, $state] = $this->store();

        $store->consume('expired', Limit::perSecond(1));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

        $this->assertSame(1, $store->pruneExpiredRows());
        $this->assertFalse($state->table()->exist('expired'));
    }

    public function testPrunesEveryExpiredCollisionRowInOnePass(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 00:00:00');
        [$store, $state] = $this->store(rows: 64);
        $now = (int) CarbonImmutable::now()->getPreciseTimestamp(6);
        $capacity = $this->fillUntilAllocationFails($state, $now - 1);

        $this->assertGreaterThan(1, $capacity['inserted']);
        $this->assertSame($capacity['inserted'], $store->pruneExpiredRows());
        $this->assertSame(0, $state->table()->count());
    }

    public function testPeriodicMaintenanceReportsPostPrunePressure(): void
    {
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Swoole rate limiter table [swoole] is nearing capacity.',
                m::on(fn (array $context): bool => $context['threshold'] === 0.0010000000000000009),
            );
        [$store] = $this->store(memoryLimitBuffer: 0.999, logger: $logger);

        $store->consume('live', Limit::perMinute(1));

        $this->assertSame(0, $store->maintain());
    }

    public function testFullTablePrunesExpiredRowsAndRetriesOnce(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 00:00:00');
        [$store, $state] = $this->store(rows: 64);
        $table = $state->table();
        $now = (int) CarbonImmutable::now()->getPreciseTimestamp(6);
        $capacity = $this->fillUntilAllocationFails($state, $now + 60_000_000);
        $this->assertTrue($table->set($capacity['conflict_key'], [
            'value' => 1,
            'available_at' => $now - 1,
            'expires_at' => $now - 1,
        ]));

        $result = @$store->consume($capacity['failed_key'], Limit::perMinute(1));

        $this->assertTrue($result->allowed());
        $this->assertFalse($table->exist($capacity['conflict_key']));
        $this->assertTrue($table->exist($capacity['failed_key']));
    }

    public function testFullTableOfLiveRowsFailsClosed(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 00:00:00');
        [$store, $state] = $this->store(rows: 64);
        $now = (int) CarbonImmutable::now()->getPreciseTimestamp(6);
        $capacity = $this->fillUntilAllocationFails($state, $now + 60_000_000);

        $this->expectException(SwooleTableFullException::class);
        $this->expectExceptionMessage('cannot allocate a new entry after pruning expired state');

        @$store->consume($capacity['failed_key'], Limit::perMinute(1));
    }

    public function testCorruptStateFailsClosed(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 00:00:00');
        [$store, $state] = $this->store();
        $expiresAt = (int) CarbonImmutable::now()->getPreciseTimestamp(6) + 60_000_000;
        $this->assertTrue($state->table()->set('corrupt', [
            'value' => 1,
            'available_at' => $expiresAt + 1,
            'expires_at' => $expiresAt,
        ]));

        $this->expectException(UnexpectedValueException::class);

        $store->consume('corrupt', Limit::perMinute(10));
    }

    /**
     * @return array{SwooleStore, TableState}
     */
    private function store(
        int $rows = 128,
        float $memoryLimitBuffer = 0.05,
        ?LoggerInterface $logger = null,
    ): array {
        $manager = new TableManager(new Repository([
            'rate-limiter' => [
                'stores' => [
                    'swoole' => [
                        'driver' => 'swoole',
                        'rows' => $rows,
                        'conflict_proportion' => 0.2,
                    ],
                ],
            ],
        ]));
        $state = $manager->get('swoole');

        return [
            new SwooleStore($state, $memoryLimitBuffer, $logger ?? new NullLogger),
            $state,
        ];
    }

    /**
     * Fill the collision pool and return an exact key that cannot be allocated.
     *
     * @return array{failed_key: string, conflict_key: string, inserted: int}
     */
    private function fillUntilAllocationFails(TableState $state, int $expiresAt): array
    {
        $table = $state->table();
        $availableSlices = (int) $table->stats()['available_slice_num'];
        $conflictKey = null;
        $inserted = 0;

        for ($index = 0; $index < 10_000; ++$index) {
            $key = "capacity:{$index}";
            $stored = @$table->set($key, [
                'value' => 1,
                'available_at' => $expiresAt,
                'expires_at' => $expiresAt,
            ]);

            if (! $stored) {
                if ($conflictKey === null) {
                    $this->fail('The test table failed before allocating a conflict row.');
                }

                return [
                    'failed_key' => $key,
                    'conflict_key' => $conflictKey,
                    'inserted' => $inserted,
                ];
            }

            ++$inserted;
            $remainingSlices = (int) $table->stats()['available_slice_num'];

            if ($remainingSlices < $availableSlices) {
                $conflictKey = $key;
            }

            $availableSlices = $remainingSlices;
        }

        $this->fail('The test table did not reach capacity.');
    }

    protected function rateLimiterStoreContract(): Limiter
    {
        [$store] = $this->store();

        return new Limiter(
            $store,
            new KeyResolver('swoole-contract', static fn (): ?string => null),
        );
    }

    protected function advanceRateLimiterStoreContractClock(int $seconds): bool
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));

        return true;
    }
}
