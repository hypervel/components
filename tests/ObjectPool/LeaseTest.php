<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\ObjectPool\Contracts\ObjectPool as ObjectPoolContract;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

class LeaseTest extends TestCase
{
    /** @var list<SimpleObjectPool> */
    private array $pools = [];

    protected function tearDownInCoroutine(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }
    }

    public function testGetAndReleaseFinalizeExactlyOnce(): void
    {
        $pool = $this->pool();
        $object = $pool->get();
        $lease = new Lease($pool, $object);

        $this->assertSame($object, $lease->get());

        $lease->release();
        $lease->release();

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testGetRejectsAReleasedLease(): void
    {
        $pool = $this->pool();
        $lease = new Lease($pool, $pool->get());
        $lease->release();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The pool lease has already been finalized.');

        $lease->get();
    }

    public function testReleaseCallbackRunsBeforeTheObjectReturnsToThePool(): void
    {
        $pool = $this->pool();
        $object = $pool->get();
        $callbackObject = null;
        $borrowedDuringCallback = null;
        $lease = new Lease(
            $pool,
            $object,
            function (object $released) use ($pool, &$callbackObject, &$borrowedDuringCallback): void {
                $callbackObject = $released;
                $borrowedDuringCallback = $pool->getBorrowedObjectNumber();
            },
        );

        $lease->release();

        $this->assertSame($object, $callbackObject);
        $this->assertSame(1, $borrowedDuringCallback);
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
    }

    public function testThrowingReleaseCallbackDiscardsTheObjectAndPropagates(): void
    {
        $destroyed = [];
        $pool = $this->pool(function (object $object) use (&$destroyed): void {
            $destroyed[] = $object;
        });
        $object = $pool->get();
        $expected = new RuntimeException('reset failed');
        $lease = new Lease($pool, $object, function () use ($expected): never {
            throw $expected;
        });

        try {
            $lease->release();
            $this->fail('Expected the reset failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertSame([$object], $destroyed);
        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
    }

    public function testDiscardFailureDoesNotMaskAReleaseCallbackFailure(): void
    {
        $container = $this->container();
        $callbackFailure = new RuntimeException('reset failed');
        $discardFailure = new RuntimeException('discard failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($discardFailure);
        $container->instance(ExceptionHandler::class, $handler);

        $pool = new ContractOnlyObjectPool;
        $pool->discardException = $discardFailure;
        $object = new stdClass;
        $lease = new Lease($pool, $object, function () use ($callbackFailure): never {
            throw $callbackFailure;
        });

        try {
            $lease->release();
            $this->fail('Expected the reset failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackFailure, $exception);
        }

        $this->assertSame([$object], $pool->discarded);

        unset($lease);
        gc_collect_cycles();

        $this->assertSame([$object], $pool->discarded);
    }

    public function testDiscardDestroysExactlyOnce(): void
    {
        $destroyed = [];
        $pool = $this->pool(function (object $object) use (&$destroyed): void {
            $destroyed[] = $object;
        });
        $object = $pool->get();
        $lease = new Lease($pool, $object);

        $lease->discard();
        $lease->discard();
        $lease->release();

        $this->assertSame([$object], $destroyed);
        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }

    public function testDestructorReleasesAnAbandonedBorrow(): void
    {
        $pool = $this->pool();
        $object = $pool->get();
        $lease = new Lease($pool, $object);

        unset($lease);
        gc_collect_cycles();

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $borrowed = $pool->get();
        $this->assertSame($object, $borrowed);
        $pool->release($borrowed);
    }

    public function testDestructorReportsAndSwallowsFinalizationFailures(): void
    {
        $container = $this->container();
        $expected = new RuntimeException('reset failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($expected);
        $container->instance(ExceptionHandler::class, $handler);

        $pool = $this->pool();
        $lease = new Lease($pool, $pool->get(), function () use ($expected): never {
            throw $expected;
        });

        unset($lease);
        gc_collect_cycles();

        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }

    public function testLeaseAcceptsAContractImplementationThatDoesNotExtendTheBasePool(): void
    {
        $pool = new ContractOnlyObjectPool;
        $released = new stdClass;
        $discarded = new stdClass;

        (new Lease($pool, $released))->release();
        (new Lease($pool, $discarded))->discard();

        $this->assertSame([$released], $pool->released);
        $this->assertSame([$discarded], $pool->discarded);
    }

    /**
     * Create a tracked object pool.
     */
    private function pool(?Closure $destroyCallback = null): SimpleObjectPool
    {
        $pool = new SimpleObjectPool(
            static fn (): object => new stdClass,
            PoolOptions::fromArray([]),
            $destroyCallback,
        );

        $this->pools[] = $pool;

        return $pool;
    }

    private function container(): Container
    {
        $container = new Container;
        Container::setInstance($container);

        return $container;
    }
}

class ContractOnlyObjectPool implements ObjectPoolContract
{
    public array $released = [];

    public array $discarded = [];

    public ?RuntimeException $discardException = null;

    public function get(): object
    {
        return new stdClass;
    }

    public function release(object $object): void
    {
        $this->released[] = $object;
    }

    public function discard(object $object): void
    {
        $this->discarded[] = $object;

        if ($this->discardException !== null) {
            throw $this->discardException;
        }
    }

    public function sweepExpired(): void
    {
    }

    public function trimIdle(): void
    {
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function isIdle(): bool
    {
        return false;
    }

    public function getBorrowedObjectNumber(): int
    {
        return 0;
    }

    public function getCurrentObjectNumber(): int
    {
        return 0;
    }

    public function getObjectNumberInPool(): int
    {
        return 0;
    }

    public function getOptions(): PoolOptions
    {
        return PoolOptions::fromArray([]);
    }

    public function getStats(): array
    {
        return ['total' => 0, 'idle' => 0, 'borrowed' => 0, 'closed' => false];
    }
}
