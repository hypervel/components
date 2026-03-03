<?php

declare(strict_types=1);

namespace Hypervel\Tests\Dispatcher;

use Hypervel\Dispatcher\AdaptedRequestHandler;
use Hypervel\Dispatcher\Psr15AdapterMiddleware;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * @internal
 * @coversNothing
 */
class Psr15AdapterMiddlewareTest extends TestCase
{
    public function testHandle()
    {
        $request = m::mock(ServerRequestInterface::class);

        $middleware = m::mock(MiddlewareInterface::class);
        $middleware->shouldReceive('process')
            ->with($request, m::type(AdaptedRequestHandler::class), 'foo')
            ->once()
            ->andReturn($mockedResponse = m::mock(ResponseInterface::class));

        $response = (new Psr15AdapterMiddleware($middleware))
            ->handle($request, fn () => null, 'foo');

        $this->assertSame($mockedResponse, $response);
    }
}
