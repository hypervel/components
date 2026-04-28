<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Contracts\Session\Session;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordMiddlewareTest extends TestCase
{
    public function testUsingGeneratesCorrectMiddlewareString()
    {
        $this->assertSame(
            RequirePassword::class . ':,',
            RequirePassword::using(null, null)
        );

        $this->assertSame(
            RequirePassword::class . ':custom.route,300',
            RequirePassword::using('custom.route', 300)
        );
    }

    public function testPassesThroughWhenPasswordConfirmationIsFresh()
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at', 0)
            ->andReturn(999); // Confirmed 1 second ago

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);

        $responseFactory = m::mock(ResponseFactory::class);
        $urlGenerator = m::mock(UrlGenerator::class);

        $middleware = new RequirePassword($responseFactory, $urlGenerator);

        $expectedResponse = new Response('ok');
        $result = $middleware->handle($request, fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);

        Carbon::setTestNow();
    }

    public function testReturnsJson423WhenStaleAndRequestExpectsJson()
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(20000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at', 0)
            ->andReturn(0); // Never confirmed

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);
        $request->shouldReceive('expectsJson')->andReturnTrue();

        $jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423);
        $responseFactory = m::mock(ResponseFactory::class);
        $responseFactory->shouldReceive('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->once()
            ->andReturn($jsonResponse);

        $urlGenerator = m::mock(UrlGenerator::class);

        $middleware = new RequirePassword($responseFactory, $urlGenerator);
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame($jsonResponse, $result);

        Carbon::setTestNow();
    }

    public function testRedirectsWhenStaleAndRequestDoesNotExpectJson()
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(20000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at', 0)
            ->andReturn(0);

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);
        $request->shouldReceive('expectsJson')->andReturnFalse();

        $redirectResponse = m::mock(RedirectResponse::class);
        $responseFactory = m::mock(ResponseFactory::class);
        $responseFactory->shouldReceive('redirectGuest')
            ->with('/password/confirm')
            ->once()
            ->andReturn($redirectResponse);

        $urlGenerator = m::mock(UrlGenerator::class);
        $urlGenerator->shouldReceive('route')
            ->with('password.confirm')
            ->andReturn('/password/confirm');

        $middleware = new RequirePassword($responseFactory, $urlGenerator);
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame($redirectResponse, $result);

        Carbon::setTestNow();
    }

    public function testCustomRouteIsUsed()
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(20000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at', 0)
            ->andReturn(0);

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);
        $request->shouldReceive('expectsJson')->andReturnFalse();

        $redirectResponse = m::mock(RedirectResponse::class);
        $responseFactory = m::mock(ResponseFactory::class);
        $responseFactory->shouldReceive('redirectGuest')
            ->with('/custom-confirm')
            ->once()
            ->andReturn($redirectResponse);

        $urlGenerator = m::mock(UrlGenerator::class);
        $urlGenerator->shouldReceive('route')
            ->with('custom.confirm')
            ->andReturn('/custom-confirm');

        $middleware = new RequirePassword($responseFactory, $urlGenerator);
        $result = $middleware->handle(
            $request,
            fn () => new Response('should not reach'),
            'custom.confirm',
        );

        $this->assertSame($redirectResponse, $result);

        Carbon::setTestNow();
    }

    public function testCustomTimeoutIsHonored()
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at', 0)
            ->andReturn(990); // Confirmed 10 seconds ago

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);

        $responseFactory = m::mock(ResponseFactory::class);
        $urlGenerator = m::mock(UrlGenerator::class);

        $middleware = new RequirePassword($responseFactory, $urlGenerator);

        // With default timeout (10800), 10 seconds would pass through
        $expectedResponse = new Response('ok');
        $result = $middleware->handle($request, fn () => $expectedResponse);
        $this->assertSame($expectedResponse, $result);

        // With custom timeout of 5 seconds, 10 seconds ago is stale
        $request->shouldReceive('expectsJson')->andReturnTrue();
        $jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423);
        $responseFactory->shouldReceive('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->once()
            ->andReturn($jsonResponse);

        $result = $middleware->handle(
            $request,
            fn () => new Response('should not reach'),
            null,
            5,
        );
        $this->assertSame($jsonResponse, $result);

        Carbon::setTestNow();
    }
}
