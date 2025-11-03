<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Exceptions\ForbiddenException;
use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\Http\Middleware\Authenticate;
use Hypervel\Http\Response;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @coversNothing
 */
class AuthTest extends IntegrationTestCase
{
    public function testAuthenticationCallbackWorks()
    {
        Horizon::auth(function (ServerRequestInterface $request) {
            return $request->getAttribute('user') === 'foo';
        });

        $fooRequestMock = Mockery::mock(ServerRequestInterface::class);
        $fooRequestMock->shouldReceive('getAttribute')->with('user')->andReturn('foo');

        $barRequestMock = Mockery::mock(ServerRequestInterface::class);
        $barRequestMock->shouldReceive('getAttribute')->with('user')->andReturn('bar');

        $this->assertTrue(Horizon::check($fooRequestMock));
        $this->assertFalse(Horizon::check($barRequestMock));
    }

    public function testAuthenticationMiddlewareCanPass()
    {
        Horizon::auth(function () {
            return true;
        });

        $middleware = new Authenticate();
        $requestMock = Mockery::mock(ServerRequestInterface::class);
        $response = new Response();

        $responseFromMiddleware = $middleware->handle(
            $requestMock,
            fn () => $response
        );

        $this->assertEquals($response, $responseFromMiddleware);
    }

    public function testAuthenticationMiddlewareThrowsOnFailure()
    {
        $this->expectException(ForbiddenException::class);

        Horizon::auth(function () {
            return false;
        });

        $middleware = new Authenticate();
        $requestMock = Mockery::mock(ServerRequestInterface::class);
        $response = new Response();

        $middleware->handle(
            $requestMock,
            fn () => $response
        );
    }
}
