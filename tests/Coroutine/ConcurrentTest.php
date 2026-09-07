<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Coroutine as FrameworkCoroutine;
use Hypervel\Coroutine\Exceptions\ChannelClosedException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;

class ConcurrentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContainer();
    }

    public function testConcurrent()
    {
        $concurrent = new Concurrent($limit = 10);
        $this->assertSame($limit, $concurrent->getLimit());
        $this->assertTrue($concurrent->isEmpty());
        $this->assertFalse($concurrent->isFull());

        $count = 0;
        for ($i = 0; $i < 15; ++$i) {
            $concurrent->create(function () use (&$count) {
                Coroutine::sleep(0.05);
                ++$count;
            });
        }

        $this->assertTrue($concurrent->isFull());
        $this->assertSame(5, $count);
        $this->assertSame($limit, $concurrent->getRunningCoroutineCount());

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }

        $this->assertSame(15, $count);
    }

    public function testException()
    {
        $con = new Concurrent(10);
        $count = 0;

        for ($i = 0; $i < 15; ++$i) {
            $con->create(function () use (&$count) {
                Coroutine::sleep(0.05);
                ++$count;
                throw new Exception('ddd');
            });
        }

        $this->assertSame(5, $count);
        $this->assertSame(10, $con->getRunningCoroutineCount());

        while (! $con->isEmpty()) {
            Coroutine::sleep(0.01);
        }
        $this->assertSame(15, $count);
    }

    public function testWaitForAvailableSlotWakesWhenARunningCoroutineFinishes(): void
    {
        $concurrent = new Concurrent(1);
        $finished = false;

        $concurrent->create(function () use (&$finished): void {
            Coroutine::sleep(0.01);
            $finished = true;
        });

        $this->assertTrue($concurrent->isFull());
        $this->assertTrue($concurrent->waitForAvailableSlot(1));
        $this->assertTrue($finished);
        $this->assertTrue($concurrent->isEmpty());
    }

    public function testWaitForAvailableSlotReturnsFalseAfterTimeout(): void
    {
        $concurrent = new Concurrent(1);

        $concurrent->create(static function (): void {
            Coroutine::sleep(0.2);
        });

        $this->assertTrue($concurrent->isFull());
        $this->assertFalse($concurrent->waitForAvailableSlot(0.01));
        $this->assertSame(1, $concurrent->getRunningCoroutineCount());

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }
    }

    public function testWaitForAvailableSlotConvertsNonThrowingCancellation(): void
    {
        $releaseChild = new Channel(1);
        $concurrent = new Concurrent(1);
        $concurrent->create(static function () use ($releaseChild): void {
            $releaseChild->pop();
        });
        $cancellation = null;

        $waitingCoroutine = EngineCoroutine::create(function () use ($concurrent, &$cancellation): void {
            try {
                $concurrent->waitForAvailableSlot();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            }
        });

        try {
            $this->assertTrue(EngineCoroutine::cancelById($waitingCoroutine->getId()));
            $this->assertInstanceOf(CanceledException::class, $cancellation);
            $this->assertSame('Waiting for a concurrency slot was canceled.', $cancellation->getMessage());
        } finally {
            $releaseChild->push(true);
        }
    }

    #[DataProvider('creationMethods')]
    public function testCreationCancellationWhileStartupReportingYieldsReleasesItsSlotOnce(string $method): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);
        $concurrent = new ConcurrentTestConcurrent(1);
        $hookFailure = new RuntimeException('The startup hook failed.');
        $reportStarted = new Channel(1);
        $releaseReport = new Channel(1);
        $parentCoroutineId = null;
        $parentCancellation = null;
        $childCoroutineId = null;
        $childBodyRan = false;

        $handler->shouldReceive('report')
            ->once()
            ->with($hookFailure)
            ->andReturnUsing(static function () use ($reportStarted, $releaseReport, &$childCoroutineId): void {
                $childCoroutineId = EngineCoroutine::id();
                $reportStarted->push(true);
                $releaseReport->pop();
            });

        FrameworkCoroutine::afterCreated(static function () use ($hookFailure): void {
            throw $hookFailure;
        });

        $canceller = EngineCoroutine::create(static function () use ($reportStarted, &$parentCoroutineId): void {
            $reportStarted->pop();

            if (is_int($parentCoroutineId)) {
                EngineCoroutine::cancelById($parentCoroutineId, throwException: true);
            }
        });

        $parent = EngineCoroutine::create(function () use (
            $concurrent,
            $method,
            &$parentCoroutineId,
            &$parentCancellation,
            &$childBodyRan,
        ): void {
            $parentCoroutineId = EngineCoroutine::id();

            try {
                $concurrent->{$method}(static function () use (&$childBodyRan): void {
                    $childBodyRan = true;
                });
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        try {
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertSame(1, $concurrent->getRunningCoroutineCount());
            $this->assertFalse($childBodyRan);
        } finally {
            $releaseReport->push(true, 0.001);

            if (is_int($childCoroutineId) && FrameworkCoroutine::exists($childCoroutineId)) {
                FrameworkCoroutine::join([$childCoroutineId], 1);
            }
        }

        $this->assertTrue($childBodyRan);
        $this->assertTrue($concurrent->isEmpty());
        $this->assertFalse(FrameworkCoroutine::exists($parent->getId()));
        $this->assertFalse(FrameworkCoroutine::exists($canceller->getId()));
    }

    public static function creationMethods(): array
    {
        return [
            'create' => ['create'],
            'fork' => ['fork'],
        ];
    }

    public function testClosedChannelIsNotReportedAsAConcurrencyTimeout(): void
    {
        $concurrent = new ConcurrentTestConcurrent(1);
        $concurrent->closeForTest();

        $this->expectException(ChannelClosedException::class);
        $this->expectExceptionMessage('The concurrency channel is closed.');

        $concurrent->waitForAvailableSlot(0.001);
    }

    protected function getContainer(): void
    {
        Container::setInstance(new Container);
    }
}

class ConcurrentTestConcurrent extends Concurrent
{
    public function closeForTest(): void
    {
        $this->channel->close();
    }
}
