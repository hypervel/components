<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler;

use Exception;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\ExceptionHandler\ExceptionHandlerDispatcher;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\HttpMessage\Base\Response;
use Hypervel\Tests\ExceptionHandler\Stub\BarExceptionHandler;
use Hypervel\Tests\ExceptionHandler\Stub\FooExceptionHandler;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;

use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class ExceptionHandlerTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testStopPropagation()
    {
        $handlers = [
            BarExceptionHandler::class,
            FooExceptionHandler::class,
        ];

        $container = $this->getContainer();

        parallel([function () use ($container, $handlers) {
            $exception = new Exception('xxx', 500);

            Context::set(ResponseInterface::class, new Response());

            $dispatcher = new ExceptionHandlerDispatcher($container);
            $dispatcher->dispatch($exception, $handlers);

            $this->assertSame(FooExceptionHandler::class, Context::get('test.exception-handler.latest-handler'));
        }]);

        parallel([function () use ($container, $handlers) {
            $exception = new Exception('xxx', 0);

            Context::set(ResponseInterface::class, new Response());

            $dispatcher = new ExceptionHandlerDispatcher($container);
            $dispatcher->dispatch($exception, $handlers);

            $this->assertSame(BarExceptionHandler::class, Context::get('test.exception-handler.latest-handler'));
        }]);

        parallel([function () use ($container, $handlers) {
            $exception = new Exception('xxx', 500);

            Context::set(ResponseInterface::class, new Response());

            $dispatcher = new ExceptionHandlerDispatcher($container);
            $dispatcher->dispatch($exception, $handlers);

            $this->assertSame(FooExceptionHandler::class, Context::get('test.exception-handler.latest-handler'));
        }]);
    }

    protected function getContainer(): ContainerContract
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('make')->with(BarExceptionHandler::class)->andReturn(new BarExceptionHandler());
        $container->shouldReceive('make')->with(FooExceptionHandler::class)->andReturn(new FooExceptionHandler());

        return $container;
    }
}
