<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\ObjectPool\Channel as ObjectPoolChannel;
use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

use function Hypervel\Coroutine\parallel;

class ObjectPoolTest extends TestCase
{
    public function testCloseDrainsIdleObjectsAndIsIdempotent(): void
    {
        $destroyed = [];
        $pool = $this->pool(
            ['max_objects' => 2],
            destroyCallback: function (object $object) use (&$destroyed): void {
                $destroyed[] = $object;
            },
        );
        $objects = [$pool->get(), $pool->get()];

        foreach ($objects as $object) {
            $pool->release($object);
        }

        $pool->close();
        $pool->close();

        $this->assertTrue($pool->isClosed());
        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getObjectNumberInPool());
        $this->assertEqualsCanonicalizing($objects, $destroyed);
    }

    public function testBorrowFromClosedPoolThrows(): void
    {
        $pool = $this->pool();
        $pool->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot borrow from a closed pool.');

        $pool->get();
    }

    public function testObjectReleasedAfterCloseIsDestroyed(): void
    {
        $destroyed = [];
        $pool = $this->pool(
            destroyCallback: function (object $object) use (&$destroyed): void {
                $destroyed[] = $object;
            },
        );
        $object = $pool->get();

        $pool->close();
        $pool->release($object);

        $this->assertSame([$object], $destroyed);
        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getObjectNumberInPool());
    }

    public function testCloseWakesEveryParkedBorrower(): void
    {
        $pool = $this->pool(['max_objects' => 1, 'wait_timeout' => 0.2]);
        $borrowed = $pool->get();
        $messages = [];

        foreach ([0, 1] as $index) {
            Coroutine::create(function () use ($pool, &$messages, $index): void {
                try {
                    $pool->get();
                } catch (RuntimeException $exception) {
                    $messages[$index] = $exception->getMessage();
                }
            });
        }

        usleep(5_000);
        $pool->close();
        usleep(5_000);
        $pool->release($borrowed);

        ksort($messages);
        $this->assertSame([
            'Cannot borrow from a closed pool.',
            'Cannot borrow from a closed pool.',
        ], $messages);
    }

    public function testCloseDuringSuspendedFactoryDestroysTheOrphan(): void
    {
        $object = new stdClass;
        $destroyed = [];
        $pool = $this->pool(
            factory: function () use ($object): object {
                usleep(10_000);

                return $object;
            },
            destroyCallback: function (object $destroyedObject) use (&$destroyed): void {
                $destroyed[] = $destroyedObject;
            },
        );
        $message = null;

        Coroutine::create(function () use ($pool, &$message): void {
            try {
                $pool->get();
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
            }
        });

        usleep(2_000);
        $pool->close();
        usleep(15_000);

        $this->assertSame('Cannot borrow from a closed pool.', $message);
        $this->assertSame([$object], $destroyed);
        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }

    public function testForeignAndDoubleReleasesAreRejected(): void
    {
        $pool = $this->pool();
        $object = $pool->get();
        $pool->release($object);

        try {
            $pool->release($object);
            $this->fail('A double release must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not checked out', $exception->getMessage());
        }

        try {
            $pool->release(new stdClass);
            $this->fail('A foreign release must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not manage', $exception->getMessage());
        }

        $this->assertSame(1, $pool->getCurrentObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $pool->close();
    }

    public function testDoubleDestroyIsRejectedBeforeStateChanges(): void
    {
        $pool = $this->pool();
        $object = $pool->get();
        $pool->discard($object);

        try {
            $pool->destroyForTest($object);
            $this->fail('Destroying an unmanaged object must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot destroy an object this pool does not manage.', $exception->getMessage());
        }

        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }

    public function testDuplicateFactoryOutputIsRejectedAndWakesAWaiter(): void
    {
        $shared = new stdClass;
        $calls = 0;
        $pool = $this->pool(
            ['max_objects' => 2, 'wait_timeout' => 0.2],
            function () use (&$calls, $shared): object {
                ++$calls;

                if ($calls === 1) {
                    return $shared;
                }

                if ($calls === 2) {
                    usleep(5_000);

                    return $shared;
                }

                return new stdClass;
            },
        );
        $first = $pool->get();

        $results = parallel([
            function () use ($pool): string {
                try {
                    $pool->get();
                } catch (RuntimeException $exception) {
                    return $exception->getMessage();
                }

                return 'unexpected';
            },
            function () use ($pool): string {
                $object = $pool->get();
                $pool->release($object);

                return 'borrowed';
            },
        ]);

        $this->assertStringContainsString('already manages', $results[0]);
        $this->assertSame('borrowed', $results[1]);
        $this->assertSame(3, $calls);

        $pool->release($first);
        $pool->close();
    }

    public function testYieldingFactoriesNeverExceedCapacity(): void
    {
        $factoriesRunning = 0;
        $maximumFactoriesRunning = 0;
        $pool = $this->pool(
            ['max_objects' => 2, 'wait_timeout' => 0.5],
            function () use (&$factoriesRunning, &$maximumFactoriesRunning): object {
                ++$factoriesRunning;
                $maximumFactoriesRunning = max($maximumFactoriesRunning, $factoriesRunning);
                usleep(5_000);
                --$factoriesRunning;

                return new stdClass;
            },
        );

        $results = parallel(array_fill(0, 8, function () use ($pool): bool {
            $object = $pool->get();
            usleep(2_000);
            $pool->release($object);

            return true;
        }));

        $this->assertSame(array_fill(0, 8, true), $results);
        $this->assertSame(2, $maximumFactoriesRunning);
        $this->assertSame(2, $pool->getCurrentObjectNumber());
        $pool->close();
    }

    public function testCreationFailureWakesAnotherBorrower(): void
    {
        $calls = 0;
        $pool = $this->pool(
            ['max_objects' => 1, 'wait_timeout' => 0.2],
            function () use (&$calls): object {
                ++$calls;

                if ($calls === 1) {
                    usleep(5_000);
                    throw new RuntimeException('factory failed');
                }

                return new stdClass;
            },
        );

        $results = parallel([
            function () use ($pool): string {
                try {
                    $pool->get();
                } catch (RuntimeException $exception) {
                    return $exception->getMessage();
                }

                return 'unexpected';
            },
            function () use ($pool): string {
                $object = $pool->get();
                $pool->release($object);

                return 'borrowed';
            },
        ]);

        $this->assertSame(['factory failed', 'borrowed'], $results);
        $pool->close();
    }

    public function testDiscardWakesAWaitingBorrowerToCreateAReplacement(): void
    {
        $pool = $this->pool(['max_objects' => 1, 'wait_timeout' => 0.2]);
        $borrowed = $pool->get();
        $replacement = null;

        Coroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->get();
            $pool->release($replacement);
        });

        usleep(5_000);
        $pool->discard($borrowed);
        usleep(5_000);

        $this->assertInstanceOf(stdClass::class, $replacement);
        $this->assertNotSame($borrowed, $replacement);
        $pool->close();
    }

    public function testMaintenanceDestroyWakesAWaitingBorrower(): void
    {
        $destroying = false;
        $pool = $this->pool(
            ['max_objects' => 1, 'max_lifetime' => 1],
            destroyCallback: function () use (&$destroying): void {
                $destroying = true;
                usleep(5_000);
                $destroying = false;
            },
        );
        $expired = $pool->get();
        $pool->release($expired);
        $pool->ageCreation($expired, 2.0);
        $replacement = null;

        Coroutine::create(function () use ($pool): void {
            $pool->sweepExpired();
        });

        usleep(1_000);
        $this->assertTrue($destroying);

        Coroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->get();
            $pool->release($replacement);
        });

        usleep(10_000);
        $this->assertInstanceOf(stdClass::class, $replacement);
        $this->assertNotSame($expired, $replacement);
        $pool->close();
    }

    public function testExhaustedPoolUsesOneWaitTimeoutFailurePath(): void
    {
        $pool = $this->pool(['max_objects' => 1, 'wait_timeout' => 0.001]);
        $pool->get();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Object pool exhausted. Cannot create new object before wait_timeout.');

        $pool->get();
    }

    public function testCheckoutPerformsOneFinalPassAfterADeadlineRelease(): void
    {
        $pool = $this->pool(['max_objects' => 1, 'wait_timeout' => 0.001]);
        $borrowed = $pool->get();
        $channel = new DeadlineObjectPoolChannel(function () use ($borrowed, $pool): void {
            $pool->release($borrowed);
        });
        $pool->replaceChannel($channel);

        $this->assertSame($borrowed, $returned = $pool->get());
        $this->assertSame(1, $channel->waitCount);

        $pool->release($returned);
        $pool->close();
    }

    public function testCheckoutPerformsOneFinalPassAfterADeadlineDiscard(): void
    {
        $pool = $this->pool(['max_objects' => 1, 'wait_timeout' => 0.001]);
        $borrowed = $pool->get();
        $channel = new DeadlineObjectPoolChannel(function () use ($borrowed, $pool): void {
            $pool->discard($borrowed);
        });
        $pool->replaceChannel($channel);

        $this->assertNotSame($borrowed, $replacement = $pool->get());
        $this->assertSame(1, $channel->waitCount);

        $pool->release($replacement);
        $pool->close();
    }

    public function testSweepExpiredDestroysBelowTheRetentionFloor(): void
    {
        $pool = $this->pool([
            'min_retained_objects' => 1,
            'max_objects' => 1,
            'max_lifetime' => 1,
        ]);
        $object = $pool->get();
        $pool->release($object);
        $pool->ageCreation($object, 2.0);

        $pool->sweepExpired();

        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getObjectNumberInPool());
        $pool->close();
    }

    public function testTrimIdleRespectsTheRetentionFloor(): void
    {
        $pool = $this->pool([
            'min_retained_objects' => 1,
            'max_objects' => 3,
            'max_idle_time' => 1,
        ]);
        $objects = [$pool->get(), $pool->get(), $pool->get()];

        foreach ($objects as $object) {
            $pool->release($object);
            $pool->ageRelease($object, 2.0);
        }

        $pool->trimIdle();

        $this->assertSame(1, $pool->getCurrentObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $pool->close();
    }

    public function testIdleTrimmingCanBeDisabled(): void
    {
        $pool = $this->pool(['max_idle_time' => 0]);
        $object = $pool->get();
        $pool->release($object);
        $pool->ageRelease($object, 10_000.0);

        $pool->trimIdle();

        $this->assertSame(1, $pool->getObjectNumberInPool());
        $pool->close();
    }

    public function testMaintenanceRequeuesPreserveReleaseTimestamps(): void
    {
        $pool = $this->pool(['max_lifetime' => 60, 'max_idle_time' => 60]);
        $object = $pool->get();
        $pool->release($object);
        $pool->ageRelease($object, 10.0);
        $releasedAt = $pool->releaseTime($object);

        $pool->sweepExpired();
        $pool->trimIdle();

        $this->assertSame($releasedAt, $pool->releaseTime($object));
        $pool->close();
    }

    public function testPoolIdleTtlRequiresNoBorrowedOrInFlightObjects(): void
    {
        $pool = $this->pool(['idle_ttl' => 0.001]);
        $pool->agePool(1.0);
        $this->assertTrue($pool->isIdle());

        $object = $pool->get();
        $pool->agePool(1.0);
        $this->assertFalse($pool->isIdle());

        $pool->release($object);
        $pool->agePool(1.0);
        $this->assertTrue($pool->isIdle());
        $pool->close();

        $suspended = $this->pool(
            ['idle_ttl' => 0.001],
            function (): object {
                usleep(10_000);

                return new stdClass;
            },
        );
        Coroutine::create(function () use ($suspended): void {
            try {
                $suspended->get();
            } catch (RuntimeException) {
            }
        });

        usleep(2_000);
        $suspended->agePool(1.0);
        $this->assertFalse($suspended->isIdle());
        $suspended->close();
        usleep(12_000);
    }

    public function testPoolIdleTtlCanBeDisabled(): void
    {
        $pool = $this->pool(['idle_ttl' => null]);
        $pool->agePool(10_000.0);

        $this->assertFalse($pool->isIdle());
        $pool->close();
    }

    public function testCheckoutReplacesConsecutiveExpiredObjects(): void
    {
        $destroyed = [];
        $pool = $this->pool(
            ['max_objects' => 2, 'max_lifetime' => 1],
            destroyCallback: function (object $object) use (&$destroyed): void {
                $destroyed[] = $object;
            },
        );
        $expired = [$pool->get(), $pool->get()];

        foreach ($expired as $object) {
            $pool->release($object);
            $pool->ageCreation($object, 2.0);
        }

        $replacement = $pool->get();

        $this->assertEqualsCanonicalizing($expired, $destroyed);
        $this->assertFalse(in_array($replacement, $expired, true));
        $this->assertSame(1, $pool->getCurrentObjectNumber());
        $this->assertSame(1, $pool->getBorrowedObjectNumber());
        $pool->release($replacement);
        $pool->close();
    }

    public function testFreshObjectReceivesItsFirstCheckoutWithTinyMaximumLifetime(): void
    {
        $creations = 0;
        $pool = $this->pool(
            ['max_objects' => 1, 'max_lifetime' => 1e-9, 'wait_timeout' => 0.01],
            function () use (&$creations): object {
                ++$creations;

                return new stdClass;
            },
        );

        $first = $pool->get();

        $this->assertSame(1, $creations);

        $pool->release($first);
        $second = $pool->get();

        $this->assertSame(2, $creations);
        $this->assertNotSame($first, $second);

        $pool->release($second);
        $pool->close();
    }

    public function testDestroyCallbackFailuresAreReportedWithoutLeavingManagedGhosts(): void
    {
        $container = $this->container();
        $failure = new RuntimeException('destroy failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->twice()->with($failure);
        $container->instance(ExceptionHandler::class, $handler);
        $pool = new InspectableObjectPool(
            PoolOptions::fromArray(['max_objects' => 2]),
            static fn (): object => new stdClass,
            function () use ($failure): never {
                throw $failure;
            },
        );
        $discarded = $pool->get();
        $idle = $pool->get();
        $pool->release($idle);

        $pool->discard($discarded);
        $pool->close();

        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(0, $pool->getObjectNumberInPool());
    }

    public function testStatsUseTrackedOwnershipState(): void
    {
        $pool = $this->pool(['max_objects' => 2]);
        $borrowed = $pool->get();
        $idle = $pool->get();
        $pool->release($idle);

        $this->assertSame([
            'total' => 2,
            'idle' => 1,
            'borrowed' => 1,
            'closed' => false,
        ], $pool->getStats());

        $pool->release($borrowed);
        $pool->close();

        $this->assertSame([
            'total' => 0,
            'idle' => 0,
            'borrowed' => 0,
            'closed' => true,
        ], $pool->getStats());
    }

    public function testHugeFiniteDurationsSaturateWithoutOverflowingLifecycleArithmetic(): void
    {
        $creations = 0;
        $pool = $this->pool(
            [
                'min_retained_objects' => 0,
                'wait_timeout' => PHP_INT_MAX,
                'max_lifetime' => PHP_INT_MAX,
                'max_idle_time' => PHP_INT_MAX,
                'idle_ttl' => PHP_INT_MAX,
            ],
            factory: function () use (&$creations): object {
                if (++$creations > 1) {
                    throw new RuntimeException('An overflowing lifetime caused immediate replacement.');
                }

                return new stdClass;
            },
        );

        $this->assertSame(PHP_INT_MAX, $pool->nanosecondsForTest((float) PHP_INT_MAX));
        $this->assertSame(PHP_INT_MAX, $pool->deadlineForTest((float) PHP_INT_MAX));

        $object = $pool->get();
        $pool->release($object);
        $pool->sweepExpired();
        $pool->trimIdle();

        $this->assertSame(1, $creations);
        $this->assertSame(1, $pool->getCurrentObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $this->assertFalse($pool->isIdle());
        $pool->close();
    }

    private function pool(
        array $options = [],
        ?Closure $factory = null,
        ?Closure $destroyCallback = null,
    ): InspectableObjectPool {
        return new InspectableObjectPool(
            PoolOptions::fromArray($options),
            $factory ?? static fn (): object => new stdClass,
            $destroyCallback,
        );
    }

    private function container(): Container
    {
        $container = new Container;
        Container::setInstance($container);

        return $container;
    }
}

class InspectableObjectPool extends ObjectPool
{
    public function __construct(
        PoolOptions $options,
        protected Closure $factory,
        ?Closure $destroyCallback = null,
    ) {
        parent::__construct($options, $destroyCallback);
    }

    public function destroyForTest(object $object): void
    {
        $this->destroyObject($object);
    }

    public function ageCreation(object $object, float $seconds): void
    {
        $this->creationTimes[spl_object_id($object)] = hrtime(true) - (int) ($seconds * 1e9);
    }

    public function ageRelease(object $object, float $seconds): void
    {
        $this->releaseTimes[spl_object_id($object)] = hrtime(true) - (int) ($seconds * 1e9);
    }

    public function releaseTime(object $object): int
    {
        return $this->releaseTimes[spl_object_id($object)];
    }

    public function nanosecondsForTest(float $seconds): int
    {
        return $this->nanoseconds($seconds);
    }

    public function deadlineForTest(float $seconds): int
    {
        return $this->deadline($seconds);
    }

    public function agePool(float $seconds): void
    {
        $this->lastUsedAt = hrtime(true) - (int) ($seconds * 1e9);
    }

    public function replaceChannel(ObjectPoolChannel $channel): void
    {
        $this->channel = $channel;
    }

    protected function createObject(): object
    {
        return ($this->factory)();
    }
}

class DeadlineObjectPoolChannel extends ObjectPoolChannel
{
    public int $waitCount = 0;

    public function __construct(protected Closure $onWait)
    {
        parent::__construct(1);
    }

    public function wait(float $timeout): bool
    {
        ++$this->waitCount;
        ($this->onWait)();

        return false;
    }
}
