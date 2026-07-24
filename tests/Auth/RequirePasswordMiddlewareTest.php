<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Contracts\Session\Session;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Middleware\PrefersJsonResponses;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordMiddlewareTest extends TestCase
{
    public function testUsingGeneratesCorrectMiddlewareString(): void
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

    public function testPassesThroughWhenPasswordConfirmationIsFresh(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_web', 0)
            ->andReturn(999); // Confirmed 1 second ago

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);

        $middleware = $this->middleware();

        $expectedResponse = new Response('ok');
        $result = $middleware->handle($request, fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testReturnsJson423WhenStaleAndRequestExpectsJson(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(20000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_web', 0)
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

        $middleware = $this->middleware(responseFactory: $responseFactory, urlGenerator: $urlGenerator);
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame($jsonResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testPreferredJsonResponsesTurnWildcardRequestsIntoJsonResponses(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(20000));

        try {
            $session = m::mock(Session::class);
            $session->shouldReceive('get')
                ->with('auth.password_confirmed_at_web', 0)
                ->andReturn(0);

            $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => '*/*']);
            $request->setHypervelSession($session);

            $jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423);
            $responseFactory = m::mock(ResponseFactory::class);
            $responseFactory->shouldReceive('json')
                ->with(['message' => 'Password confirmation required.'], 423)
                ->once()
                ->andReturn($jsonResponse);

            $middleware = $this->middleware(
                responseFactory: $responseFactory,
                urlGenerator: m::mock(UrlGenerator::class),
            );

            $response = (new PrefersJsonResponses)->handle(
                $request,
                static fn (Request $request): Response => $middleware->handle(
                    $request,
                    static fn (): Response => new Response('should not reach'),
                ),
            );

            $this->assertSame($jsonResponse, $response);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testPreferredJsonResponsesPreserveExplicitHtmlRedirects(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(20000));

        try {
            $session = m::mock(Session::class);
            $session->shouldReceive('get')
                ->with('auth.password_confirmed_at_web', 0)
                ->andReturn(0);

            $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
            $request->setHypervelSession($session);

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

            $middleware = $this->middleware(
                responseFactory: $responseFactory,
                urlGenerator: $urlGenerator,
            );

            $response = (new PrefersJsonResponses)->handle(
                $request,
                static fn (Request $request): Response => $middleware->handle(
                    $request,
                    static fn (): Response => new Response('should not reach'),
                ),
            );

            $this->assertSame($redirectResponse, $response);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testRedirectsWhenStaleAndRequestDoesNotExpectJson(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(20000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_web', 0)
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

        $middleware = $this->middleware(responseFactory: $responseFactory, urlGenerator: $urlGenerator);
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame($redirectResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testCustomRouteIsUsed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(20000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_web', 0)
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

        $middleware = $this->middleware(responseFactory: $responseFactory, urlGenerator: $urlGenerator);
        $result = $middleware->handle(
            $request,
            fn () => new Response('should not reach'),
            'custom.confirm',
        );

        $this->assertSame($redirectResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testCustomTimeoutIsHonored(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_web', 0)
            ->andReturn(990); // Confirmed 10 seconds ago

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);

        $responseFactory = m::mock(ResponseFactory::class);
        $urlGenerator = m::mock(UrlGenerator::class);

        $middleware = $this->middleware(responseFactory: $responseFactory, urlGenerator: $urlGenerator);

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

        CarbonImmutable::setTestNow();
    }

    public function testConfirmationIsScopedToCurrentGuard(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(20000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_admin', 0)
            ->andReturn(0);

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);
        $request->shouldReceive('expectsJson')->andReturnTrue();

        $responseFactory = m::mock(ResponseFactory::class);
        $responseFactory->shouldReceive('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->once()
            ->andReturn($jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423));

        $result = $this->middleware(responseFactory: $responseFactory, guard: 'admin')
            ->handle($request, fn () => new Response('should not reach'));

        $this->assertSame($jsonResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testPerGuardTimeoutIsHonored(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_admin', 0)
            ->twice()
            ->andReturn(989, 991);

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);

        $responseFactory = m::mock(ResponseFactory::class);
        $responseFactory->shouldReceive('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->once()
            ->andReturn($jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423));

        $config = new Repository([
            'auth' => [
                'guards' => [
                    'admin' => [
                        'password_timeout' => 10,
                    ],
                ],
            ],
        ]);

        $middleware = $this->middleware(responseFactory: $responseFactory, config: $config, guard: 'admin');

        $request->shouldReceive('expectsJson')->andReturnTrue();
        $result = $middleware->handle($request, fn () => new Response('should not reach'));
        $this->assertSame($jsonResponse, $result);

        $expectedResponse = new Response('ok');
        $result = $middleware->handle($request, fn () => $expectedResponse);
        $this->assertSame($expectedResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testRouteParameterOverridesPerGuardTimeout(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1000));

        $session = m::mock(Session::class);
        $session->shouldReceive('get')
            ->with('auth.password_confirmed_at_admin', 0)
            ->andReturn(994);

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session);
        $request->shouldReceive('expectsJson')->andReturnTrue();

        $responseFactory = m::mock(ResponseFactory::class);
        $responseFactory->shouldReceive('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->once()
            ->andReturn($jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423));

        $config = new Repository([
            'auth' => [
                'guards' => [
                    'admin' => [
                        'password_timeout' => 10,
                    ],
                ],
            ],
        ]);

        $result = $this->middleware(responseFactory: $responseFactory, config: $config, guard: 'admin')
            ->handle($request, fn () => new Response('should not reach'), null, 5);

        $this->assertSame($jsonResponse, $result);

        CarbonImmutable::setTestNow();
    }

    private function middleware(
        ?ResponseFactory $responseFactory = null,
        ?UrlGenerator $urlGenerator = null,
        ?Repository $config = null,
        string $guard = 'web'
    ): RequirePassword {
        $auth = m::mock(AuthFactory::class);
        $auth->shouldReceive('getDefaultDriver')->andReturn($guard);

        return new RequirePassword(
            $responseFactory ?? m::mock(ResponseFactory::class),
            $urlGenerator ?? m::mock(UrlGenerator::class),
            $auth,
            $config ?? new Repository,
        );
    }
}
