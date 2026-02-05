<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Exception;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Exception\CoroutineDestroyedException;
use Mockery;
use Throwable;

use function Hypervel\Coroutine\defer;
use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class CoroutineTest extends CoroutineTestCase
{
    public function testCoroutineParentId(): void
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

    public function testCoroutineParentIdHasBeenDestroyed(): void
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

    public function testCoroutineAndDeferWithException(): void
    {
        $container = Mockery::mock(ContainerContract::class);
        ApplicationContext::setContainer($container);

        $exception = new Exception();
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('get')->with(ExceptionHandlerContract::class)
            ->andReturn($handler = Mockery::mock(ExceptionHandlerContract::class));
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

    public function testAfterCreatedCallbacksAreExecuted(): void
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

    public function testAfterCreatedCallbacksExecuteInOrder(): void
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

    public function testFlushAfterCreatedClearsCallbacks(): void
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

    public function testAfterCreatedCallbackExceptionDoesNotStopOthers(): void
    {
        $container = Mockery::mock(ContainerContract::class);
        ApplicationContext::setContainer($container);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('get')->with(ExceptionHandlerContract::class)
            ->andReturn($handler = Mockery::mock(ExceptionHandlerContract::class));
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
