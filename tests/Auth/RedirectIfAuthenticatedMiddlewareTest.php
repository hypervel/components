<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticatedMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RedirectIfAuthenticated::flushState();
    }

    public function testItCanGenerateDefinitionViaStaticMethod()
    {
        $signature = RedirectIfAuthenticated::using('foo');
        $this->assertSame('Hypervel\Auth\Middleware\RedirectIfAuthenticated:foo', $signature);

        $signature = RedirectIfAuthenticated::using('foo', 'bar');
        $this->assertSame('Hypervel\Auth\Middleware\RedirectIfAuthenticated:foo,bar', $signature);

        $signature = RedirectIfAuthenticated::using('foo', 'bar', 'baz');
        $this->assertSame('Hypervel\Auth\Middleware\RedirectIfAuthenticated:foo,bar,baz', $signature);
    }

    public function testPassesThroughWhenGuest()
    {
        $this->swapAuthGuard(authenticated: false);

        $request = Request::create('/login', 'GET');
        $response = new Response('ok');

        $middleware = new RedirectIfAuthenticated;
        $result = $middleware->handle($request, fn () => $response);

        $this->assertSame($response, $result);
    }

    public function testRedirectsWhenAuthenticated()
    {
        $this->swapAuthGuard(authenticated: true);

        $request = Request::create('/login', 'GET');

        $middleware = new RedirectIfAuthenticated;
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame(302, $result->getStatusCode());
    }

    public function testCustomRedirectCallbackIsUsed()
    {
        $this->swapAuthGuard(authenticated: true);

        RedirectIfAuthenticated::redirectUsing(fn (Request $request) => '/custom-path');

        $request = Request::create('/login', 'GET');

        $middleware = new RedirectIfAuthenticated;
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame(302, $result->getStatusCode());
        $this->assertStringContainsString('/custom-path', $result->headers->get('Location'));
    }

    public function testDefaultRedirectFallsBackToSlash()
    {
        $this->swapAuthGuard(authenticated: true);

        $request = Request::create('/login', 'GET');

        $middleware = new RedirectIfAuthenticated;
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        // No 'dashboard' or 'home' routes registered, so it falls back to '/'
        $this->assertSame(302, $result->getStatusCode());
    }

    public function testMultipleGuardsRedirectsIfAnyAuthenticated()
    {
        $guestGuard = m::mock(Guard::class);
        $guestGuard->shouldReceive('check')->andReturn(false);

        $authGuard = m::mock(Guard::class);
        $authGuard->shouldReceive('check')->andReturn(true);

        $factory = m::mock(AuthFactory::class);
        $factory->shouldReceive('guard')->with('web')->andReturn($guestGuard);
        $factory->shouldReceive('guard')->with('api')->andReturn($authGuard);
        Auth::swap($factory);

        $request = Request::create('/login', 'GET');

        $middleware = new RedirectIfAuthenticated;
        $result = $middleware->handle($request, fn () => new Response('should not reach'), 'web', 'api');

        $this->assertSame(302, $result->getStatusCode());
    }

    public function testPassesThroughWhenAllGuardsAreGuests()
    {
        $guard1 = m::mock(Guard::class);
        $guard1->shouldReceive('check')->andReturn(false);

        $guard2 = m::mock(Guard::class);
        $guard2->shouldReceive('check')->andReturn(false);

        $factory = m::mock(AuthFactory::class);
        $factory->shouldReceive('guard')->with('web')->andReturn($guard1);
        $factory->shouldReceive('guard')->with('api')->andReturn($guard2);
        Auth::swap($factory);

        $request = Request::create('/login', 'GET');
        $response = new Response('ok');

        $middleware = new RedirectIfAuthenticated;
        $result = $middleware->handle($request, fn () => $response, 'web', 'api');

        $this->assertSame($response, $result);
    }

    public function testFlushStateClearsRedirectCallback()
    {
        RedirectIfAuthenticated::redirectUsing(fn () => '/custom');

        RedirectIfAuthenticated::flushState();

        // After flush, authenticated user should use default redirect (not custom)
        $this->swapAuthGuard(authenticated: true);

        $request = Request::create('/login', 'GET');

        $middleware = new RedirectIfAuthenticated;
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame(302, $result->getStatusCode());
        $location = $result->headers->get('Location');
        $this->assertStringNotContainsString('/custom', $location);
    }

    /**
     * Swap the Auth facade with a mock guard.
     */
    protected function swapAuthGuard(bool $authenticated): void
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')->andReturn($authenticated);

        $factory = m::mock(AuthFactory::class);
        $factory->shouldReceive('guard')->andReturn($guard);
        Auth::swap($factory);
    }
}
