<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Coroutine\wait;

/**
 * @internal
 * @coversNothing
 */
class WaiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance(Waiter::class, new Waiter());
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
        $deferredWorkCompleted = false;

        $result = wait(function () use (&$deferredWorkCompleted) {
            Coroutine::defer(function () use (&$deferredWorkCompleted) {
                Coroutine::sleep(0.001);
                $deferredWorkCompleted = true;
            });

            return 'result';
        });

        $this->assertSame('result', $result);
        $this->assertTrue($deferredWorkCompleted);
    }

    public function testWaitRethrowsExceptionAfterDeferredWorkCompletes()
    {
        $deferredWorkCompleted = false;
        $message = uniqid();

        try {
            wait(function () use (&$deferredWorkCompleted, $message) {
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
        $callback = function () {
            Coroutine::sleep(0.05);
            return true;
        };

        $this->expectException(WaitTimeoutException::class);
        $this->expectExceptionMessage('Channel wait failed, reason: Timed out for 0.001 s');
        wait($callback, 0.001);
    }
}
