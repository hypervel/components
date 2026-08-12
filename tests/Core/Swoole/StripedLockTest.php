<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Swoole;

use Hypervel\Core\Swoole\StripedLock;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Atomic;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\run;

class StripedLockTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testContendedLockBacksOffAndAcquiresAfterRelease(): void
    {
        $locks = new TestStripedLock;
        $locks->hold('key');
        $called = false;

        run(function () use ($locks, &$called): void {
            go(function () use ($locks): void {
                usleep(5_000);
                $locks->releaseHeld('key');
            });

            $locks->withLock('key', function () use (&$called): void {
                $called = true;
            });
        });

        $this->assertTrue($called);
    }

    public function testLockFailureIsBoundedAndDescriptive(): void
    {
        $locks = new TestStripedLock;
        $locks->hold('key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timed out acquiring a Swoole striped lock.');

        $locks->withLock('key', static fn (): bool => true);
    }

    public function testDifferentStripesProceedIndependently(): void
    {
        $locks = new TestStripedLock;
        $locks->hold('first');

        $this->assertNotSame($locks->stripe('first'), $locks->stripe('second'));
        $this->assertTrue($locks->withLock('second', static fn (): bool => true));
    }

    public function testSelectedLocksDeduplicateAndAcquireStripesInAscendingOrder(): void
    {
        $locks = new RecordingSelectedStripedLock;
        $lowKey = $locks->keyForStripe(7);
        $highKey = $locks->keyForStripe(51);

        $result = $locks->withLocks(
            [$highKey, $lowKey, $highKey],
            static fn (): string => 'completed',
        );

        $this->assertSame('completed', $result);
        $this->assertSame([7, 51], $locks->acquiredStripes);
        $this->assertSame([51, 7], $locks->releasedStripes);
    }

    public function testSelectedLockFailureReleasesEarlierAcquisitions(): void
    {
        $locks = new FailingSelectedStripedLock;

        try {
            $locks->withLocks([
                $locks->keyForStripe(2),
                $locks->keyForStripe(19),
                $locks->keyForStripe(37),
            ], static fn (): bool => true);
            $this->fail('The third selected stripe acquisition should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic selected stripe acquisition failure.', $exception->getMessage());
        }

        $this->assertTrue($locks->firstAcquiredStripesAreReleased());
    }

    public function testAllLockFailureReleasesEarlierAcquisitions(): void
    {
        $locks = new FailingAllStripedLock;

        try {
            $locks->withAllLocks(static fn (): bool => true);
            $this->fail('The third stripe acquisition should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic stripe acquisition failure.', $exception->getMessage());
        }

        $this->assertTrue($locks->firstAcquiredStripesAreReleased());
    }
}

class TestStripedLock extends StripedLock
{
    protected const int ACQUIRE_TIMEOUT_NANOSECONDS = 50_000_000;

    public function hold(string $key): void
    {
        $this->lockFor($key)->set(1);
    }

    public function releaseHeld(string $key): void
    {
        $this->lockFor($key)->set(0);
    }

    public function stripe(string $key): int
    {
        return $this->lockIndexFor($key);
    }

    public function keyForStripe(int $stripe): string
    {
        for ($index = 0; $index < 10_000; ++$index) {
            $key = "stripe-key-{$index}";

            if ($this->lockIndexFor($key) === $stripe) {
                return $key;
            }
        }

        throw new RuntimeException("Unable to find a key for stripe [{$stripe}].");
    }
}

class RecordingSelectedStripedLock extends TestStripedLock
{
    /** @var list<int> */
    public array $acquiredStripes = [];

    /** @var list<int> */
    public array $releasedStripes = [];

    protected function acquire(Atomic $lock): void
    {
        $this->acquiredStripes[] = $this->stripeFor($lock);
        parent::acquire($lock);
    }

    protected function release(Atomic $lock): void
    {
        $this->releasedStripes[] = $this->stripeFor($lock);
        parent::release($lock);
    }

    protected function stripeFor(Atomic $lock): int
    {
        $stripe = array_search($lock, $this->locks, true);

        if ($stripe === false) {
            throw new RuntimeException('The Atomic does not belong to this striped lock.');
        }

        return $stripe;
    }
}

class FailingSelectedStripedLock extends TestStripedLock
{
    private int $acquisitions = 0;

    /** @var list<int> */
    private array $acquiredStripes = [];

    protected function acquire(Atomic $lock): void
    {
        if (++$this->acquisitions === 3) {
            throw new RuntimeException('Synthetic selected stripe acquisition failure.');
        }

        parent::acquire($lock);

        $stripe = array_search($lock, $this->locks, true);

        if ($stripe === false) {
            throw new RuntimeException('The Atomic does not belong to this striped lock.');
        }

        $this->acquiredStripes[] = $stripe;
    }

    public function firstAcquiredStripesAreReleased(): bool
    {
        foreach ($this->acquiredStripes as $stripe) {
            if ($this->locks[$stripe]->get() !== 0) {
                return false;
            }
        }

        return true;
    }
}

class FailingAllStripedLock extends StripedLock
{
    private int $acquisitions = 0;

    protected function acquire(Atomic $lock): void
    {
        if (++$this->acquisitions === 3) {
            throw new RuntimeException('Synthetic stripe acquisition failure.');
        }

        parent::acquire($lock);
    }

    public function firstAcquiredStripesAreReleased(): bool
    {
        return $this->locks[0]->get() === 0
            && $this->locks[1]->get() === 0;
    }
}
