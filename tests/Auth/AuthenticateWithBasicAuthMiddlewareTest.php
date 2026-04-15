<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\AuthenticateWithBasicAuth;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithBasicAuthMiddlewareTest extends TestCase
{
    public function testUsingGeneratesCorrectMiddlewareString()
    {
        $this->assertSame(
            AuthenticateWithBasicAuth::class . ':',
            AuthenticateWithBasicAuth::using()
        );

        $this->assertSame(
            AuthenticateWithBasicAuth::class . ':api',
            AuthenticateWithBasicAuth::using('api')
        );

        $this->assertSame(
            AuthenticateWithBasicAuth::class . ':api,username',
            AuthenticateWithBasicAuth::using('api', 'username')
        );
    }

    public function testItCallsBasicWithDefaultField()
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('basic')->with('email')->once()->andReturnNull();

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->with(null)->andReturn($guard);

        $middleware = new AuthenticateWithBasicAuth($authFactory);
        $request = Request::create('/', 'GET');
        $expectedResponse = new Response('ok');

        $result = $middleware->handle($request, fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);
    }

    public function testItCallsBasicWithCustomField()
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('basic')->with('username')->once()->andReturnNull();

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->with('api')->andReturn($guard);

        $middleware = new AuthenticateWithBasicAuth($authFactory);
        $request = Request::create('/', 'GET');
        $expectedResponse = new Response('ok');

        $result = $middleware->handle($request, fn () => $expectedResponse, 'api', 'username');

        $this->assertSame($expectedResponse, $result);
    }

    public function testItUsesSpecifiedGuard()
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('basic')->with('email')->once()->andReturnNull();

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->with('web')->andReturn($guard);

        $middleware = new AuthenticateWithBasicAuth($authFactory);
        $request = Request::create('/', 'GET');
        $expectedResponse = new Response('ok');

        $result = $middleware->handle($request, fn () => $expectedResponse, 'web');

        $this->assertSame($expectedResponse, $result);
    }
}
