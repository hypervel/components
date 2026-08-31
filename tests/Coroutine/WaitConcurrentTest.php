<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class WaitConcurrentTest extends TestCase
{
    public function testForkParticipatesInWaitTracking(): void
    {
        $release = new Channel(1);
        $concurrent = new WaitConcurrent(1);

        $concurrent->fork(static function () use ($release): void {
            $release->pop();
        });

        $this->assertFalse($concurrent->wait(0.005));

        $release->push(true);

        $this->assertTrue($concurrent->wait(1.0));
        $this->assertTrue($concurrent->isEmpty());
    }

    public function testForkBalancesWaitTrackingWhenContextReplicationFails(): void
    {
        CoroutineContext::set('throwing', new ThrowingReplicableContext);
        $concurrent = new WaitConcurrent(1);

        try {
            $concurrent->fork(static function (): void {
                throw new RuntimeException('The child must not be created.');
            });
            $this->fail('Expected context replication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to replicate context.', $exception->getMessage());
        }

        $this->assertTrue($concurrent->wait(0.005));
        $this->assertTrue($concurrent->isEmpty());
    }

    #[DataProvider('creationMethods')]
    public function testCreationCancellationWhileStartupReportingYieldsReleasesOwnershipOnce(string $method): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);
        $concurrent = new WaitConcurrent(1);
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

        Coroutine::afterCreated(static function () use ($hookFailure): void {
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
            $this->assertFalse($childBodyRan);
            $this->assertTrue($concurrent->wait(0.001));
            $this->assertTrue($concurrent->isEmpty());
        } finally {
            $releaseReport->push(true, 0.001);

            if (is_int($childCoroutineId) && Coroutine::exists($childCoroutineId)) {
                EngineCoroutine::cancelById($childCoroutineId, throwException: true);
                Coroutine::join([$childCoroutineId], 1);
            }
        }

        $this->assertFalse(Coroutine::exists($parent->getId()));
        $this->assertFalse(Coroutine::exists($canceller->getId()));
    }

    public static function creationMethods(): array
    {
        return [
            'create' => ['create'],
            'fork' => ['fork'],
        ];
    }

    public function testCancelStopsOnlyActiveBodiesAndIsIdempotent(): void
    {
        $concurrent = new WaitConcurrent(1);
        $bodyStarted = new Channel(1);
        $blocker = new Channel(1);
        $childCancellation = null;
        $childCoroutineId = null;

        $concurrent->create(static function () use ($bodyStarted, $blocker, &$childCancellation, &$childCoroutineId): void {
            $childCoroutineId = Coroutine::id();
            $bodyStarted->push(true);

            try {
                $blocker->pop();
            } catch (CanceledException $exception) {
                $childCancellation = $exception;
            }
        });

        $this->assertTrue($bodyStarted->pop());
        $concurrent->cancel();
        $concurrent->cancel();

        $this->assertInstanceOf(CanceledException::class, $childCancellation);
        $this->assertTrue($concurrent->wait(0.001));
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testCancelDoesNotInterruptCompletedBodyDeferredCleanup(): void
    {
        $concurrent = new WaitConcurrent(1);
        $cleanupStarted = new Channel(1);
        $releaseCleanup = new Channel(1);
        $cleanupCompleted = false;
        $childCoroutineId = null;

        $concurrent->create(static function () use (
            $cleanupStarted,
            $releaseCleanup,
            &$cleanupCompleted,
            &$childCoroutineId,
        ): void {
            $childCoroutineId = Coroutine::id();
            Coroutine::defer(static function () use ($cleanupStarted, $releaseCleanup, &$cleanupCompleted): void {
                $cleanupStarted->push(true);
                $releaseCleanup->pop();
                $cleanupCompleted = true;
            });
        });

        $this->assertTrue($cleanupStarted->pop());
        $this->assertTrue($concurrent->wait(0.001));
        $concurrent->cancel();

        try {
            $this->assertFalse($cleanupCompleted);
            $this->assertIsInt($childCoroutineId);
            $this->assertTrue(Coroutine::exists($childCoroutineId));
        } finally {
            $releaseCleanup->push(true);

            if (is_int($childCoroutineId)) {
                Coroutine::join([$childCoroutineId]);
            }
        }

        $this->assertTrue($cleanupCompleted);
    }

    public function testCancellationWhileWaitingCancelsActiveBodies(): void
    {
        $concurrent = new WaitConcurrent(1);
        $bodyStarted = new Channel(1);
        $blocker = new Channel(1);
        $childCoroutineId = null;
        $parentCancellation = null;

        $concurrent->create(static function () use ($bodyStarted, $blocker, &$childCoroutineId): void {
            $childCoroutineId = Coroutine::id();
            $bodyStarted->push(true);
            $blocker->pop();
        });
        $this->assertTrue($bodyStarted->pop());

        $parent = EngineCoroutine::create(function () use ($concurrent, &$parentCancellation): void {
            try {
                $concurrent->wait();
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        try {
            $this->assertTrue(EngineCoroutine::cancelById($parent->getId(), throwException: true));
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
        } finally {
            if (is_int($childCoroutineId) && Coroutine::exists($childCoroutineId)) {
                EngineCoroutine::cancelById($childCoroutineId, throwException: true);
            }
        }
    }
}
