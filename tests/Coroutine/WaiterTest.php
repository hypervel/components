<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\Exceptions\ChildTerminationTimeoutException;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Support\Sleep;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\wait;

class WaiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance(Waiter::class, new Waiter);
        Container::setInstance($container);
    }

    public function testDefaultPushTimeoutIsExposed(): void
    {
        $this->assertSame(10.0, Waiter::DEFAULT_PUSH_TIMEOUT_SECONDS);
        $this->assertSame(
            Waiter::DEFAULT_PUSH_TIMEOUT_SECONDS,
            (new ReflectionProperty(Waiter::class, 'pushTimeout'))->getValue(new Waiter),
        );
    }

    public function testWait(): void
    {
        $id = uniqid();
        $result = wait(function () use ($id) {
            return $id;
        });

        $this->assertSame($id, $result);

        $id = rand(0, 9999);
        $result = wait(function () use ($id) {
            return $id + 1;
        });

        $this->assertSame($id + 1, $result);
    }

    public function testWaitStartsWithFreshContextByDefault(): void
    {
        CoroutineContext::set('key_a', 'value_a');

        $this->assertNull(wait(
            static fn (): mixed => CoroutineContext::get('key_a')
        ));
    }

    public function testWaitCanCopyAllContext(): void
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $readContext = static fn (): array => [
            CoroutineContext::get('key_a'),
            CoroutineContext::get('key_b'),
        ];

        $this->assertSame(['value_a', 'value_b'], wait($readContext, copyContext: true));
        $this->assertSame(['value_a', 'value_b'], wait($readContext, copyContext: []));
    }

    public function testWaitCanCopySelectedContextKeys(): void
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $result = (new Waiter)->wait(
            static fn (): array => [
                CoroutineContext::get('key_a'),
                CoroutineContext::get('key_b'),
            ],
            copyContext: ['key_a'],
        );

        $this->assertSame(['value_a', null], $result);
    }

    public function testCopiedContextOmitsSelectedNonCopyableValues(): void
    {
        $resource = new class implements NonCopyableContext {
        };

        CoroutineContext::set('resource', $resource);
        CoroutineContext::set('request_id', 'abc');

        $result = wait(
            static fn (): array => [
                CoroutineContext::get('resource'),
                CoroutineContext::get('request_id'),
            ],
            copyContext: ['resource', 'request_id'],
        );

        $this->assertSame([null, 'abc'], $result);
        $this->assertSame($resource, CoroutineContext::get('resource'));
    }

    public function testContextReplicationFailureIsReportedInsteadOfTimingOut(): void
    {
        CoroutineContext::set('throwing', new ThrowingReplicableContext);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to replicate context.');

        (new Waiter(0.01))->wait(
            static fn (): string => 'never',
            copyContext: true,
        );
    }

    public function testWaitNone(): void
    {
        $callback = function () {
        };
        $result = wait($callback);
        $this->assertSame($result, $callback());
        $this->assertSame(null, $result);

        $callback = function () {
            return null;
        };
        $result = wait($callback);
        $this->assertSame($result, $callback());
        $this->assertSame(null, $result);
    }

    public function testWaitException(): void
    {
        $message = uniqid();
        $callback = function () use ($message) {
            throw new RuntimeException($message);
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        wait($callback);
    }

    #[DataProvider('copyContexts')]
    public function testParentCancellationDuringStartupReportingCancelsThePublishedChild(bool $copyContext): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);
        $waiter = new Waiter;
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
            $waiter,
            $copyContext,
            &$parentCoroutineId,
            &$parentCancellation,
            &$childBodyRan,
        ): void {
            $parentCoroutineId = EngineCoroutine::id();

            try {
                $waiter->wait(
                    static function () use (&$childBodyRan): void {
                        $childBodyRan = true;
                    },
                    copyContext: $copyContext,
                );
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        try {
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertFalse($childBodyRan);
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
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

    public static function copyContexts(): array
    {
        return [
            'fresh context' => [false],
            'copied context' => [true],
        ];
    }

    public function testParentCancellationCancelsTheOwnedChildAndEscapesExactly(): void
    {
        $waiter = new Waiter;
        $childStarted = new Channel(1);
        $childBlocker = new Channel(1);
        $childCoroutineId = null;
        $childExited = false;
        $parentCancellation = null;

        $parent = EngineCoroutine::create(function () use (
            $waiter,
            $childStarted,
            $childBlocker,
            &$childCoroutineId,
            &$childExited,
            &$parentCancellation,
        ): void {
            try {
                $waiter->wait(function () use ($childStarted, $childBlocker, &$childCoroutineId, &$childExited): void {
                    $childCoroutineId = Coroutine::id();
                    $childStarted->push(true);

                    try {
                        $childBlocker->pop();
                    } finally {
                        $childExited = true;
                    }
                });
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        $this->assertTrue($childStarted->pop());

        try {
            $this->assertTrue(EngineCoroutine::cancelById($parent->getId(), throwException: true));
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertTrue($childExited);
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
        } finally {
            if (is_int($childCoroutineId) && Coroutine::exists($childCoroutineId)) {
                EngineCoroutine::cancelById($childCoroutineId, throwException: true);
            }
        }
    }

    public function testNonThrowingParentCancellationIsConvertedAndCancelsTheOwnedChild(): void
    {
        $waiter = new Waiter;
        $childStarted = new Channel(1);
        $childBlocker = new Channel(1);
        $childCoroutineId = null;
        $parentCancellation = null;

        $parent = EngineCoroutine::create(function () use (
            $waiter,
            $childStarted,
            $childBlocker,
            &$childCoroutineId,
            &$parentCancellation,
        ): void {
            try {
                $waiter->wait(function () use ($childStarted, $childBlocker, &$childCoroutineId): void {
                    $childCoroutineId = Coroutine::id();
                    $childStarted->push(true);
                    $childBlocker->pop();
                });
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        $this->assertTrue($childStarted->pop());

        try {
            $this->assertTrue(EngineCoroutine::cancelById($parent->getId()));
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertSame('Waiting for a child coroutine was canceled.', $parentCancellation->getMessage());
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
        } finally {
            if (is_int($childCoroutineId) && Coroutine::exists($childCoroutineId)) {
                EngineCoroutine::cancelById($childCoroutineId, throwException: true);
            }
        }
    }

    public function testIndependentChildCancellationIsWrappedForTheActiveOwner(): void
    {
        $waiter = new Waiter;
        $childStarted = new Channel(1);
        $childBlocker = new Channel(1);
        $childCoroutineId = null;
        $childCancellation = null;
        $outcome = null;

        $parent = EngineCoroutine::create(function () use (
            $waiter,
            $childStarted,
            $childBlocker,
            &$childCoroutineId,
            &$childCancellation,
            &$outcome,
        ): void {
            try {
                $outcome = $waiter->wait(function () use (
                    $childStarted,
                    $childBlocker,
                    &$childCoroutineId,
                    &$childCancellation,
                ): void {
                    $childCoroutineId = Coroutine::id();
                    $childStarted->push(true);

                    try {
                        $childBlocker->pop();
                    } catch (CanceledException $exception) {
                        $childCancellation = $exception;
                        throw $exception;
                    }
                });
            } catch (Throwable $exception) {
                $outcome = $exception;
            }
        });

        $this->assertTrue($childStarted->pop());
        $this->assertIsInt($childCoroutineId);
        $this->assertTrue(EngineCoroutine::cancelById($childCoroutineId, throwException: true));
        Coroutine::join([$parent->getId()]);

        $this->assertInstanceOf(ChildCancellationException::class, $outcome);
        $this->assertSame('A child coroutine managed by Waiter was canceled while its owner remained active.', $outcome->getMessage());
        $this->assertSame($childCancellation, $outcome->getPrevious());
    }

    public function testWaitReturnsAfterDeferredWorkCompletes(): void
    {
        $childCoroutineId = null;
        $deferredWorkCompleted = false;

        $result = wait(function () use (&$childCoroutineId, &$deferredWorkCompleted) {
            $childCoroutineId = Coroutine::id();
            Coroutine::defer(function () use (&$deferredWorkCompleted) {
                Coroutine::sleep(0.001);
                $deferredWorkCompleted = true;
            });

            return 'result';
        });

        $this->assertSame('result', $result);
        $this->assertTrue($deferredWorkCompleted);
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testWaitRethrowsExceptionAfterDeferredWorkCompletes(): void
    {
        $childCoroutineId = null;
        $deferredWorkCompleted = false;
        $message = uniqid();

        try {
            wait(function () use (&$childCoroutineId, &$deferredWorkCompleted, $message) {
                $childCoroutineId = Coroutine::id();
                Coroutine::defer(function () use (&$deferredWorkCompleted) {
                    Coroutine::sleep(0.001);
                    $deferredWorkCompleted = true;
                });

                throw new RuntimeException($message);
            });

            $this->fail('The waiter should rethrow the child coroutine exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertTrue($deferredWorkCompleted);
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testWaitReturnException(): void
    {
        $message = uniqid();
        $callback = function () use ($message) {
            return new RuntimeException($message);
        };

        $result = wait($callback);
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame($message, $result->getMessage());
    }

    public function testPushTimeout(): void
    {
        $channel = new Channel(1);
        $this->assertSame(true, $channel->push(1, 0.05));
        $this->assertSame(false, $channel->push(1, 0.05));
    }

    public function testTimeout(): void
    {
        $childCoroutineId = null;
        $callback = function () use (&$childCoroutineId) {
            $childCoroutineId = Coroutine::id();
            Coroutine::sleep(0.05);
            return true;
        };

        try {
            wait($callback, 0.001);
            $this->fail('The waiter should time out.');
        } catch (WaitTimeoutException $exception) {
            $this->assertSame('Channel wait failed, reason: Timed out for 0.001 s', $exception->getMessage());
        }

        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testTimeoutCancellationTerminatesLoopingOperations(): void
    {
        $childCoroutineId = null;
        $waiter = new class extends Waiter {
            protected float $pushTimeout = 0.001;
        };

        try {
            $waiter->wait(function () use (&$childCoroutineId): never {
                $childCoroutineId = Coroutine::id();

                while (true) {
                    Sleep::usleep(1_000);
                }
            }, 0.001);
            $this->fail('The waiter should time out.');
        } catch (WaitTimeoutException) {
        }

        try {
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
        } finally {
            if (is_int($childCoroutineId) && Coroutine::exists($childCoroutineId)) {
                EngineCoroutine::cancelById($childCoroutineId, throwException: true);
                Coroutine::join([$childCoroutineId]);
            }
        }
    }

    public function testTimeoutWaitsForDeferredCleanupWithinTheCleanupBudget(): void
    {
        $childCoroutineId = null;
        $deferredWorkCompleted = false;
        $waiter = new class extends Waiter {
            protected float $pushTimeout = 0.05;
        };

        try {
            $waiter->wait(function () use (&$childCoroutineId, &$deferredWorkCompleted): void {
                $childCoroutineId = Coroutine::id();
                Coroutine::defer(function () use (&$deferredWorkCompleted): void {
                    Coroutine::sleep(0.005);
                    $deferredWorkCompleted = true;
                });
                Coroutine::sleep(0.05);
            }, 0.001);
            $this->fail('The waiter should time out.');
        } catch (WaitTimeoutException) {
        }

        $this->assertTrue($deferredWorkCompleted);
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testStrictTimeoutThrowsTheBaseExceptionWhenTheChildStopsWithinTheCleanupBudget(): void
    {
        $childCoroutineId = null;
        $waiter = new class extends Waiter {
            protected float $pushTimeout = 0.05;
        };

        try {
            $waiter->wait(function () use (&$childCoroutineId): void {
                $childCoroutineId = Coroutine::id();
                Coroutine::sleep(0.05);
            }, 0.001, waitForChildTermination: true);
            $this->fail('The waiter should time out.');
        } catch (WaitTimeoutException $exception) {
        }

        $this->assertSame(WaitTimeoutException::class, $exception::class);
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testTimeoutReturnsWhenTheChildOutlivesTheCleanupBudget(): void
    {
        $childCoroutineId = null;
        $childCompleted = false;
        $trailingWorkStarted = new Channel(1);
        $releaseTrailingWork = new Channel(1);
        $waiter = new class extends Waiter {
            protected float $pushTimeout = 0.001;
        };

        try {
            $waiter->wait(function () use (&$childCoroutineId, &$childCompleted, $trailingWorkStarted, $releaseTrailingWork): void {
                $childCoroutineId = Coroutine::id();

                try {
                    Coroutine::sleep(0.05);
                } catch (CanceledException) {
                    $trailingWorkStarted->push(true);
                    $releaseTrailingWork->pop();
                    $childCompleted = true;
                }
            }, 0.001);
            $this->fail('The waiter should time out.');
        } catch (WaitTimeoutException) {
        }

        try {
            $this->assertTrue($trailingWorkStarted->pop(0.01));
            $this->assertFalse($childCompleted);
            $this->assertIsInt($childCoroutineId);
            $this->assertTrue(Coroutine::exists($childCoroutineId));
        } finally {
            $releaseTrailingWork->push(true);

            if (is_int($childCoroutineId)) {
                Coroutine::join([$childCoroutineId]);
            }
        }

        $this->assertTrue($childCompleted);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testStrictTimeoutWaitsForChildTerminationAfterTheCleanupBudget(): void
    {
        $childCoroutineId = null;
        $trailingWorkStarted = new Channel(1);
        $releaseTrailingWork = new Channel(1);
        $outcome = new Channel(1);
        $waiter = new class extends Waiter {
            protected float $pushTimeout = 0.001;
        };

        Container::getInstance()->instance(Waiter::class, $waiter);

        $waitingCoroutineId = Coroutine::create(function () use (
            &$childCoroutineId,
            $outcome,
            $releaseTrailingWork,
            $trailingWorkStarted,
        ): void {
            try {
                wait(function () use (&$childCoroutineId, $releaseTrailingWork, $trailingWorkStarted): void {
                    $childCoroutineId = Coroutine::id();

                    try {
                        Coroutine::sleep(0.05);
                    } catch (CanceledException) {
                        $trailingWorkStarted->push(true);
                        $releaseTrailingWork->pop();
                    }
                }, timeout: 0.002, waitForChildTermination: true);

                $outcome->push('returned');
            } catch (Throwable $exception) {
                $outcome->push($exception);
            }
        });

        try {
            $this->assertTrue($trailingWorkStarted->pop(0.01));
            $this->assertIsInt($childCoroutineId);
            $this->assertTrue(Coroutine::exists($childCoroutineId));
            $this->assertFalse($outcome->pop(0.05));
        } finally {
            $releaseTrailingWork->push(true);
            Coroutine::join([$waitingCoroutineId]);
        }

        $exception = $outcome->pop(0.01);

        $this->assertInstanceOf(ChildTerminationTimeoutException::class, $exception);
        $this->assertSame(
            'Channel wait failed, reason: Timed out for 0.002 s and child coroutine did not terminate within 0.001 s',
            $exception->getMessage(),
        );
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }
}
