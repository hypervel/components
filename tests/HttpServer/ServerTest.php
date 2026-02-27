<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Event\Dispatcher as DispatcherContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Dispatcher\HttpDispatcher;
use Hypervel\ExceptionHandler\ExceptionHandlerDispatcher;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\HttpMessage\Exceptions\BadRequestHttpException;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\HttpMessage\Server\Response as Psr7Response;
use Hypervel\HttpServer\ResponseEmitter;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\HttpServer\Stub\ServerStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * @internal
 * @coversNothing
 */
class ServerTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        parent::tearDown();
        CoordinatorManager::clear(Constants::WORKER_START);
    }

    public function testThrowExceptionInCatchOnRequest()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $container = $this->getContainer();
        $dispatcher = m::mock(ExceptionHandlerDispatcher::class);
        $emitter = m::mock(ResponseEmitter::class);
        $server = m::mock(ServerStub::class . '[initRequestAndResponse]', [
            $container,
            m::mock(HttpDispatcher::class),
            $dispatcher,
            $emitter,
        ]);

        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($exception) {
            throw new RuntimeException('Fatal Error');
        });

        $emitter->shouldReceive('emit')->once()->andReturnUsing(function ($response) {
            $this->assertInstanceOf(Psr7Response::class, $response);
            $this->assertSame(400, $response->getStatusCode());
        });

        $server->shouldReceive('initRequestAndResponse')->andReturnUsing(function () {
            // Initialize PSR-7 Request and Response objects.
            throw new BadRequestHttpException();
        });

        $server->onRequest($req = m::mock(Request::class), $res = m::mock(Response::class));
    }

    public function testOnRequest()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $container = $this->getContainer();
        $dispatcher = m::mock(ExceptionHandlerDispatcher::class);
        $emitter = m::mock(ResponseEmitter::class);
        $server = m::mock(ServerStub::class . '[initRequestAndResponse]', [
            $container,
            m::mock(HttpDispatcher::class),
            $dispatcher,
            $emitter,
        ]);

        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($exception) {
            if ($exception instanceof HttpException) {
                return (new Psr7Response())->withStatus($exception->getStatusCode());
            }
            return null;
        });

        $emitter->shouldReceive('emit')->once()->andReturnUsing(function ($response) {
            $this->assertInstanceOf(Psr7Response::class, $response);
            $this->assertSame(400, $response->getStatusCode());
        });

        $server->shouldReceive('initRequestAndResponse')->andReturnUsing(function () {
            // Initialize PSR-7 Request and Response objects.
            throw new BadRequestHttpException();
        });

        $server->onRequest($req = m::mock(Request::class), $res = m::mock(Response::class));
    }

    protected function getContainer()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnFalse();
        $container->shouldReceive('make')->with(SafeCaller::class)->andReturn(new SafeCaller($container));

        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturn(true);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(DispatcherContract::class)->andReturn($dispatcher);

        Container::setInstance($container);

        return $container;
    }
}
