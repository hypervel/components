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
use Hypervel\Http\Request;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordMiddlewareTest extends TestCase
{
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

    /**
     * Create the middleware with mocked dependencies.
     */
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
            $config ?? new Repository([
                'auth' => [
                    'password_timeout' => 10800,
                    'guards' => [
                        $guard => [
                            'password_timeout' => null,
                        ],
                    ],
                ],
            ]),
        );
    }
}
