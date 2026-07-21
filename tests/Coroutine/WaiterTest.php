<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

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

    public function testWait()
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

    public function testWaitNone()
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

    public function testWaitException()
    {
        $message = uniqid();
        $callback = function () use ($message) {
            throw new RuntimeException($message);
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        wait($callback);
    }

    public function testWaitReturnsAfterDeferredWorkCompletes()
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

    public function testWaitRethrowsExceptionAfterDeferredWorkCompletes()
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

    public function testWaitReturnException()
    {
        $message = uniqid();
        $callback = function () use ($message) {
            return new RuntimeException($message);
        };

        $result = wait($callback);
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame($message, $result->getMessage());
    }

    public function testPushTimeout()
    {
        $channel = new Channel(1);
        $this->assertSame(true, $channel->push(1, 0.05));
        $this->assertSame(false, $channel->push(1, 0.05));
    }

    public function testTimeout()
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
}
