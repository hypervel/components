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

class PreventRequestForgeryTest extends TestCase
{
    public function testSameOriginHeaderPasses(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function testSameSiteHeaderRejectedByDefault(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-site']);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function testSameSiteHeaderPassesWhenAllowed(): void
    {
        PreventRequestForgery::allowSameSite();

        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-site']);

        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function testCrossSiteWithValidTokenPasses(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'test-token');

        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function testCrossSiteWithoutTokenFails(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function testMissingHeaderWithoutTokenFails(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest();

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function testArrayTokenIsRejected(): void
    {
        $middleware = $this->createMiddleware();
        $request = $this->createRequest();
        // Malformed input must reach token validation, not fail at the getter's return type.
        $request->request->set('_token', ['test-token']);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function testOriginOnlyModeRejectsCrossSite(): void
    {
        PreventRequestForgery::useOriginOnly();

        $middleware = $this->createMiddleware();
        // Even with a valid token, origin-only mode rejects cross-site
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'test-token');

        $this->expectException(OriginMismatchException::class);

        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function testOriginOnlyModeRejectsMissingHeader(): void
    {
        PreventRequestForgery::useOriginOnly();

        $middleware = $this->createMiddleware();
        $request = $this->createRequest([], 'test-token');

        $this->expectException(OriginMismatchException::class);

        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function testOriginOnlyModePassesSameOrigin(): void
    {
        PreventRequestForgery::useOriginOnly();

        $middleware = $this->createMiddleware();
        $request = $this->createRequest(['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    /**
     * Create a request with the given headers and token.
     */
    protected function createRequest(array $server = [], ?string $token = null): Request
    {
        $request = Request::create(
            'http://example.com/test',
            'POST',
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
