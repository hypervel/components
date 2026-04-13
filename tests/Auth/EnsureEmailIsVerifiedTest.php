<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\EnsureEmailIsVerified;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal
 * @coversNothing
 */
class EnsureEmailIsVerifiedTest extends TestCase
{
    public function testItCanGenerateDefinitionViaStaticMethod()
    {
        $signature = EnsureEmailIsVerified::redirectTo('route.name');
        $this->assertSame('Hypervel\Auth\Middleware\EnsureEmailIsVerified:route.name', $signature);
    }

    public function testVerifiedUserPassesThrough()
    {
        $user = m::mock(Authenticatable::class . ',' . MustVerifyEmail::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturnTrue();

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);

        $expectedResponse = new Response('ok');
        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request, fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);
    }

    public function testUserThatDoesNotImplementMustVerifyEmailPassesThrough()
    {
        // User implements Authenticatable but NOT MustVerifyEmail
        $user = m::mock(Authenticatable::class);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);

        $expectedResponse = new Response('ok');
        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request, fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);
    }

    public function testGuestRequestReturnsJsonWhenExpectsJson()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn(null);
        $request->shouldReceive('expectsJson')->andReturnTrue();

        $this->expectException(HttpException::class);

        $middleware = new EnsureEmailIsVerified;
        $middleware->handle($request, fn () => new Response('should not reach'));
    }

    public function testUnverifiedUserReturnsJsonWhenExpectsJson()
    {
        $user = m::mock(Authenticatable::class . ',' . MustVerifyEmail::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturnFalse();

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('expectsJson')->andReturnTrue();

        $this->expectException(HttpException::class);

        $middleware = new EnsureEmailIsVerified;
        $middleware->handle($request, fn () => new Response('should not reach'));
    }

    public function testUnverifiedUserRedirectsWhenNotJson()
    {
        // Register a named route so URL::route() can resolve it
        $this->app['router']->get('/email/verify', fn () => 'verify')->name('verify.email');

        $user = m::mock(Authenticatable::class . ',' . MustVerifyEmail::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturnFalse();

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('expectsJson')->andReturnFalse();

        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request, fn () => new Response('should not reach'), 'verify.email');

        $this->assertSame(302, $result->getStatusCode());
    }

    public function testGuestRequestRedirectsWhenNotJson()
    {
        $this->app['router']->get('/email/verify', fn () => 'verify')->name('verify.email');

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn(null);
        $request->shouldReceive('expectsJson')->andReturnFalse();

        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request, fn () => new Response('should not reach'), 'verify.email');

        $this->assertSame(302, $result->getStatusCode());
    }
}
