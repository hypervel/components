<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Exception\CoroutineDestroyedException;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Throwable;

use function Hypervel\Coroutine\defer;
use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class CoroutineTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testCoroutineParentId()
    {
        $pid = Coroutine::id();
        Coroutine::create(function () use ($pid) {
            $this->assertSame($pid, Coroutine::parentId());
            $pid = Coroutine::id();
            $id = Coroutine::create(function () use ($pid) {
                $this->assertSame($pid, Coroutine::parentId(Coroutine::id()));
                usleep(1000);
            });
            Coroutine::create(function () use ($pid) {
                $this->assertSame($pid, Coroutine::parentId());
            });
            $this->assertSame($pid, Coroutine::parentId($id));
        });
    }

    public function testCoroutineParentIdHasBeenDestroyed()
    {
        $id = Coroutine::create(function () {
        });

        try {
            Coroutine::parentId($id);
            $this->assertTrue(false);
        } catch (Throwable $exception) {
            $this->assertInstanceOf(CoroutineDestroyedException::class, $exception);
        }
    }

    public function testCoroutineAndDeferWithException()
    {
        $container = new Container();
        $handler = m::mock(ExceptionHandlerContract::class);
        $container->instance(ExceptionHandlerContract::class, $handler);
        Container::setInstance($container);

        $exception = new Exception();
        $handler->shouldReceive('report')->with($exception)->twice();

        $chan = new Channel(1);
        go(static function () use ($chan, $exception) {
            defer(static function () use ($chan, $exception) {
                try {
                    throw $exception;
                } finally {
                    $chan->push(1);
                }
            });

            throw $exception;
        });

        $this->assertTrue(true);
    }

    public function testAfterCreatedCallbacksAreExecuted()
    {
        $executed = false;

        Coroutine::afterCreated(function () use (&$executed) {
            $executed = true;
        });

        Coroutine::create(function () {
            // The afterCreated callback should have run before this
        });

        $this->assertTrue($executed);

        // Clean up
        Coroutine::flushAfterCreated();
    }

    public function testAfterCreatedCallbacksExecuteInOrder()
    {
        $order = [];

        Coroutine::afterCreated(function () use (&$order) {
            $order[] = 1;
        });

        Coroutine::afterCreated(function () use (&$order) {
            $order[] = 2;
        });

        Coroutine::create(function () use (&$order) {
            $order[] = 3;
        });

        $this->assertSame([1, 2, 3], $order);

        // Clean up
        Coroutine::flushAfterCreated();
    }

    public function testFlushAfterCreatedClearsCallbacks()
    {
        $count = 0;

        Coroutine::afterCreated(function () use (&$count) {
            ++$count;
        });

        Coroutine::create(function () {});
        $this->assertSame(1, $count);

        Coroutine::flushAfterCreated();

        Coroutine::create(function () {});
        $this->assertSame(1, $count); // Should still be 1, callback was flushed
    }

    public function testAfterCreatedCallbackExceptionDoesNotStopOthers()
    {
        $container = new Container();
        $handler = m::mock(ExceptionHandlerContract::class);
        $container->instance(ExceptionHandlerContract::class, $handler);
        Container::setInstance($container);
        $handler->shouldReceive('report')->once();

        $secondCallbackRan = false;
        $mainCallableRan = false;

        Coroutine::afterCreated(function () {
            throw new Exception('First callback fails');
        });

        Coroutine::afterCreated(function () use (&$secondCallbackRan) {
            $secondCallbackRan = true;
        });

        Coroutine::create(function () use (&$mainCallableRan) {
            $mainCallableRan = true;
        });

        $this->assertTrue($secondCallbackRan);
        $this->assertTrue($mainCallableRan);

        // Clean up
        Coroutine::flushAfterCreated();
    }
}
