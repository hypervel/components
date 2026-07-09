<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use BadMethodCallException;
use DateInterval;
use DateTimeImmutable;
use Error;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Limiters\ConcurrencyLimiter;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Limiters\Lease;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Contracts\Limiters\RefreshableLease;
use Hypervel\Tests\TestCase;
use Throwable;

class ConcurrencyLimiterTest extends TestCase
{
    protected Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new Repository(new ArrayStore);
    }

    public function testItLocksTasksWhenNoSlotAvailable(): void
    {
        $store = [];

        foreach (range(1, 2) as $i) {
            (new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'key', 2, 5))->block(2, function () use (&$store, $i) {
                $store[] = $i;
            });
        }

        try {
            (new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'key', 2, 5))->block(0, function () use (&$store) {
                $store[] = 3;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        (new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'other_key', 2, 5))->block(2, function () use (&$store) {
            $store[] = 4;
        });

        $this->assertEquals([1, 2, 4], $store);
    }

    public function testItReleasesLockAfterTaskFinishes(): void
    {
        $store = [];

        foreach (range(1, 4) as $i) {
            (new ConcurrencyLimiter($this->repository->getStore(), 'key', 2, 5))->block(2, function () use (&$store, $i) {
                $store[] = $i;
            });
        }

        $this->assertEquals([1, 2, 3, 4], $store);
    }

    public function testItReleasesLockIfTaskTookTooLong(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'key', 1, 1);

        $lock->block(2, function () use (&$store) {
            $store[] = 1;
        });

        try {
            $lock->block(0, function () use (&$store) {
                $store[] = 2;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        usleep(1_200_000);

        $lock->block(0, function () use (&$store) {
            $store[] = 3;
        });

        $this->assertEquals([1, 3], $store);
    }

    public function testItFailsImmediatelyOrRetriesForAWhileBasedOnAGivenTimeout(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'key', 1, 2);

        $lock->block(2, function () use (&$store) {
            $store[] = 1;
        });

        try {
            $lock->block(0, function () use (&$store) {
                $store[] = 2;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        $lock->block(3, function () use (&$store) {
            $store[] = 3;
        });

        $this->assertEquals([1, 3], $store);
    }

    public function testItFailsAfterRetryTimeout(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'key', 1, 10);

        $lock->block(2, function () use (&$store) {
            $store[] = 1;
        });

        try {
            $lock->block(2, function () use (&$store) {
                $store[] = 2;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        $this->assertEquals([1], $store);
    }

    public function testItReleasesIfErrorIsThrown(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiter($this->repository->getStore(), 'key', 1, 5);

        try {
            $lock->block(1, function () {
                throw new Error;
            });
        } catch (Error) {
        }

        $lock = new ConcurrencyLimiter($this->repository->getStore(), 'key', 1, 5);
        $lock->block(1, function () use (&$store) {
            $store[] = 1;
        });

        $this->assertEquals([1], $store);
    }

    public function testFunnelMethodOnRepository(): void
    {
        $store = [];

        $result = $this->repository->funnel('test-funnel')
            ->limit(2)
            ->releaseAfter(5)
            ->block(2)
            ->then(function () use (&$store) {
                $store[] = 1;

                return 'ok';
            });

        $this->assertEquals([1], $store);
        $this->assertSame('ok', $result);
    }

    public function testFunnelMethodAcceptsBackedEnum(): void
    {
        $store = [];

        $result = $this->repository->funnel(ConcurrencyLimiterBackedEnum::TestFunnel)
            ->limit(2)
            ->releaseAfter(5)
            ->block(2)
            ->then(function () use (&$store) {
                $store[] = 1;

                return 'ok';
            });

        $this->assertEquals([1], $store);
        $this->assertSame('ok', $result);
    }

    public function testFunnelMethodAcceptsUnitEnum(): void
    {
        $store = [];

        $result = $this->repository->funnel(ConcurrencyLimiterUnitEnum::TestFunnel)
            ->limit(2)
            ->releaseAfter(5)
            ->block(2)
            ->then(function () use (&$store) {
                $store[] = 1;

                return 'ok';
            });

        $this->assertEquals([1], $store);
        $this->assertSame('ok', $result);
    }

    public function testFunnelBackedEnumSharesKeyWithStringEquivalent(): void
    {
        // Fill all slots using the backed enum's string value
        foreach (range(1, 2) as $i) {
            (new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'test-funnel', 2, 5))->block(2, function () {
            });
        }

        // Try to acquire via the BackedEnum — should conflict with the string key
        $result = $this->repository->funnel(ConcurrencyLimiterBackedEnum::TestFunnel)
            ->limit(2)
            ->releaseAfter(5)
            ->block(0)
            ->then(
                function () {
                    return 'success';
                },
                function () {
                    return 'failed';
                }
            );

        $this->assertSame('failed', $result);
    }

    public function testFunnelThrowsExceptionWhenStoreDoesNotSupportLocks(): void
    {
        $store = $this->createStub(Store::class);
        $repository = new Repository($store);

        $this->assertNotInstanceOf(LockProvider::class, $store);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('This cache store does not support locks.');

        $repository->funnel('test');
    }

    public function testFunnelWithFailureCallback(): void
    {
        $store = [];

        // Fill all slots without releasing
        foreach (range(1, 2) as $i) {
            (new ConcurrencyLimiterMockThatDoesntRelease($this->repository->getStore(), 'funnel-key', 2, 5))->block(2, function () use (&$store, $i) {
                $store[] = $i;
            });
        }

        // Try to acquire when all slots are full
        $result = $this->repository->funnel('funnel-key')
            ->limit(2)
            ->releaseAfter(5)
            ->block(0)
            ->then(
                function () use (&$store) {
                    $store[] = 'success';
                },
                function ($e) use (&$store) {
                    $this->assertInstanceOf(LimiterTimeoutException::class, $e);
                    $store[] = 'failed';

                    return 'failure-result';
                }
            );

        $this->assertEquals([1, 2, 'failed'], $store);
        $this->assertSame('failure-result', $result);
    }

    public function testFunnelAcquireReturnsLease(): void
    {
        $lease = $this->repository->funnel('lease-key')
            ->limit(1)
            ->releaseAfter(5)
            ->block(0)
            ->acquire();

        try {
            $this->assertInstanceOf(Lease::class, $lease);
            $this->assertInstanceOf(RefreshableLease::class, $lease);
            $this->assertNotEmpty($lease->owner());
        } finally {
            $lease->release();
        }
    }

    public function testFunnelLeaseReleasesSlot(): void
    {
        $lease = $this->repository->funnel('lease-release-key')
            ->limit(1)
            ->releaseAfter(5)
            ->block(0)
            ->acquire();

        $this->assertTrue($lease->release());

        $result = $this->repository->funnel('lease-release-key')
            ->limit(1)
            ->releaseAfter(5)
            ->block(0)
            ->then(fn () => 'acquired');

        $this->assertSame('acquired', $result);
    }

    public function testFunnelDoesNotRouteCallbackTimeoutExceptionToFailureCallback(): void
    {
        $failureCalled = false;

        try {
            $this->repository->funnel('callback-exception')
                ->limit(1)
                ->releaseAfter(5)
                ->block(0)
                ->then(
                    function () {
                        throw new LimiterTimeoutException;
                    },
                    function () use (&$failureCalled) {
                        $failureCalled = true;
                    }
                );

            $this->fail('Expected LimiterTimeoutException was not thrown.');
        } catch (LimiterTimeoutException) {
        }

        $this->assertFalse($failureCalled);
    }

    public function testFunnelWithZeroLimitDoesNotRunCallback(): void
    {
        $called = false;

        $result = $this->repository->funnel('zero')
            ->limit(0)
            ->releaseAfter(5)
            ->block(0)
            ->then(
                function () use (&$called) {
                    $called = true;

                    return 'should-not-run';
                },
                fn () => 'failed',
            );

        $this->assertFalse($called);
        $this->assertSame('failed', $result);
    }

    public function testFunnelWithNegativeLimitDoesNotRunCallback(): void
    {
        $called = false;

        $result = $this->repository->funnel('neg')
            ->limit(-1)
            ->releaseAfter(5)
            ->block(0)
            ->then(
                function () use (&$called) {
                    $called = true;

                    return 'should-not-run';
                },
                fn () => 'failed',
            );

        $this->assertFalse($called);
        $this->assertSame('failed', $result);
    }

    public function testReleaseAfterAcceptsDateInterval(): void
    {
        $store = [];

        $result = $this->repository->funnel('test')
            ->limit(2)
            ->releaseAfter(new DateInterval('PT5S'))
            ->block(2)
            ->then(function () use (&$store) {
                $store[] = 1;

                return 'ok';
            });

        $this->assertEquals([1], $store);
        $this->assertSame('ok', $result);
    }

    public function testReleaseAfterAcceptsDateTime(): void
    {
        $store = [];

        $result = $this->repository->funnel('test')
            ->limit(2)
            ->releaseAfter((new DateTimeImmutable)->modify('+5 seconds'))
            ->block(2)
            ->then(function () use (&$store) {
                $store[] = 1;

                return 'ok';
            });

        $this->assertEquals([1], $store);
        $this->assertSame('ok', $result);
    }
}

class ConcurrencyLimiterMockThatDoesntRelease extends ConcurrencyLimiter
{
    public function block(int $timeout, ?callable $callback = null, int $sleep = 250): mixed
    {
        $this->acquire($timeout, $sleep);

        if (is_callable($callback)) {
            return $callback();
        }

        return true;
    }
}

enum ConcurrencyLimiterBackedEnum: string
{
    case TestFunnel = 'test-funnel';
}

enum ConcurrencyLimiterUnitEnum
{
    case TestFunnel;
}
