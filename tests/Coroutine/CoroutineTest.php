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
}
