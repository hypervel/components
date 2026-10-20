<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Session\Session;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Http\Exceptions\OriginMismatchException;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Session\TokenMismatchException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class PreventRequestForgeryTest extends TestCase
{
    public function testSameOriginHeaderPasses(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $response = $middleware->handle($request, fn (): Response => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function testSameSiteHeaderRejectedByDefault(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-site']);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    public function testSameSiteHeaderPassesWhenAllowed(): void
    {
        PreventRequestForgery::allowSameSite();

        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-site']);

        $response = $middleware->handle($request, fn (): Response => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function testCrossSiteWithValidTokenPasses(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'test-token');

        $response = $middleware->handle($request, fn (): Response => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function testCrossSiteWithoutTokenFails(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    public function testMissingHeaderWithoutTokenFails(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest();

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    public function testArrayTokenIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest();
        // Malformed input must reach token validation, not fail at the getter's return type.
        $request->request->set('_token', ['test-token']);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    #[DataProvider('verifiedMethods')]
    public function testOriginOnlyModeRejectsCrossSite(string $method): void
    {
        PreventRequestForgery::useOriginOnly();

        $middleware = $this->createMiddleware();
        // Even with a valid token, origin-only mode rejects cross-site
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'test-token', $method);

        $this->expectException(OriginMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    /**
     * Provide request methods that require origin or token verification.
     */
    public static function verifiedMethods(): array
    {
        return [
            'post' => ['POST'],
            'query' => ['QUERY'],
        ];
    }

    public function testOriginOnlyModeRejectsMissingHeader(): void
    {
        PreventRequestForgery::useOriginOnly();

        $middleware = $this->createMiddleware();
        $request = $this->createRequest([], 'test-token');

        $this->expectException(OriginMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    public function testOriginOnlyModePassesSameOrigin(): void
    {
        PreventRequestForgery::useOriginOnly();

        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $response = $middleware->handle($request, fn (): Response => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function testQueryRequestWithoutTokenFails(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site'], method: 'QUERY');

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    public function testQueryRequestWithValidTokenPasses(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'test-token', 'QUERY');

        $response = $middleware->handle($request, fn (): Response => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    #[DataProvider('methodOverrideBags')]
    public function testSpoofedQueryRequestWithoutTokenFails(string $bag): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createSpoofedQueryRequest($bag);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('OK'));
    }

    #[DataProvider('methodOverrideBags')]
    public function testSpoofedQueryRequestWithValidTokenPasses(string $bag): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createSpoofedQueryRequest($bag, 'test-token');

        $response = $middleware->handle($request, fn (): Response => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    /**
     * Provide the request bags a form may use to override its method.
     */
    public static function methodOverrideBags(): array
    {
        return [
            'query string' => ['query'],
            'body' => ['request'],
        ];
    }

    /**
     * Create a request with the given headers and token.
     */
    protected function createRequest(array $server = [], ?string $token = null, string $method = 'POST'): Request
    {
        $request = Request::create(
            'http://example.com/test',
            $method,
            $token ? ['_token' => $token] : [],
            [],
            [],
            $server
        );

        $session = m::mock(Session::class);
        $session->shouldReceive('token')->andReturn('test-token');
        $request->setHypervelSession($session);

        return $request;
    }

    /**
     * Create a cross-site form POST that overrides its method to QUERY.
     */
    protected function createSpoofedQueryRequest(string $bag, ?string $token = null): Request
    {
        Request::enableHttpMethodParameterOverride();

        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site'], $token);
        $request->{$bag}->set('_method', 'QUERY');

        $this->assertSame('POST', $request->getRealMethod());
        $this->assertSame('QUERY', $request->method());

        return $request;
    }

    /**
     * Create the middleware for origin and token verification.
     */
    protected function createMiddleware(): PreventRequestForgeryTestStub
    {
        return new PreventRequestForgeryTestStub(
            m::mock(Application::class),
            m::mock(Encrypter::class)
        );
    }
}

class PreventRequestForgeryTestStub extends PreventRequestForgery
{
    protected bool $addHttpCookie = false;

    /**
     * Determine if the application is running unit tests.
     */
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
