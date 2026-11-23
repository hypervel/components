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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordMiddlewareTest extends TestCase
{
    use VerifiesDoubles;

    public function testReturnsJson423WhenStaleAndRequestExpectsJson(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(20000));

        $session = Double::for(Session::class)->strict();
        $session->expects('get')
            ->with('auth.password_confirmed_at_web', 0)
            ->returns(0); // Never confirmed

        $request = Request::create('/');
        $request->setHypervelSession($session);
        $request->headers->set('Accept', 'application/json');

        $jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423);
        $responseFactory = Double::for(ResponseFactory::class)->strict();
        $responseFactory->expects('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->returns($jsonResponse);

        $urlGenerator = Double::for(UrlGenerator::class)->strict();

        $middleware = $this->middleware(responseFactory: $responseFactory, urlGenerator: $urlGenerator);
        $result = $middleware->handle($request, fn () => new Response('should not reach'));

        $this->assertSame($jsonResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testCustomTimeoutIsHonored(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1000));

        $session = Double::for(Session::class)->strict();
        $session->expects('get')->times(2)
            ->with('auth.password_confirmed_at_web', 0)
            ->returns(990); // Confirmed 10 seconds ago

        $request = Request::create('/');
        $request->setHypervelSession($session);

        $responseFactory = Double::for(ResponseFactory::class)->strict();
        $urlGenerator = Double::for(UrlGenerator::class)->strict();

        $middleware = $this->middleware(responseFactory: $responseFactory, urlGenerator: $urlGenerator);

        // With default timeout (10800), 10 seconds would pass through
        $expectedResponse = new Response('ok');
        $result = $middleware->handle($request, fn () => $expectedResponse);
        $this->assertSame($expectedResponse, $result);

        // With custom timeout of 5 seconds, 10 seconds ago is stale
        $request->headers->set('Accept', 'application/json');
        $jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423);
        $responseFactory->expects('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->returns($jsonResponse);

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

        $session = Double::for(Session::class)->strict();
        $session->expects('get')
            ->with('auth.password_confirmed_at_admin', 0)
            ->returns(0);

        $request = Request::create('/');
        $request->setHypervelSession($session);
        $request->headers->set('Accept', 'application/json');

        $responseFactory = Double::for(ResponseFactory::class)->strict();
        $responseFactory->expects('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->returns($jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423));

        $result = $this->middleware(responseFactory: $responseFactory, guard: 'admin')
            ->handle($request, fn () => new Response('should not reach'));

        $this->assertSame($jsonResponse, $result);

        CarbonImmutable::setTestNow();
    }

    public function testPerGuardTimeoutIsHonored(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1000));

        $session = Double::for(Session::class)->strict();
        $session->expects('get')
            ->with('auth.password_confirmed_at_admin', 0)
            ->times(2)
            ->returns(989, 991);

        $request = Request::create('/');
        $request->setHypervelSession($session);

        $responseFactory = Double::for(ResponseFactory::class)->strict();
        $responseFactory->expects('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->returns($jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423));

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

        $request->headers->set('Accept', 'application/json');
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

        $session = Double::for(Session::class)->strict();
        $session->expects('get')
            ->with('auth.password_confirmed_at_admin', 0)
            ->returns(994);

        $request = Request::create('/');
        $request->setHypervelSession($session);
        $request->headers->set('Accept', 'application/json');

        $responseFactory = Double::for(ResponseFactory::class)->strict();
        $responseFactory->expects('json')
            ->with(['message' => 'Password confirmation required.'], 423)
            ->returns($jsonResponse = new JsonResponse(['message' => 'Password confirmation required.'], 423));

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
        $auth = Double::for(AuthFactory::class)->strict();
        $auth->allows('getDefaultDriver')->returns($guard);

        return new RequirePassword(
            $responseFactory ?? Double::for(ResponseFactory::class)->strict(),
            $urlGenerator ?? Double::for(UrlGenerator::class)->strict(),
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
