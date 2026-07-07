<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Testbench\TestCase;

class EnsureFrontendRequestsAreStatefulTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('sanctum.stateful_domains', ['test.com', '*.test.com']);
    }

    public function testRequestFromFrontendIsIdentified(): void
    {
        $request = Request::create('http://localhost', server: ['HTTP_REFERER' => 'https://test.com']);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testRequestNotFromFrontend(): void
    {
        $request = Request::create('http://localhost', server: ['HTTP_REFERER' => 'https://wrong.com']);

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testOriginFallback(): void
    {
        $request = Request::create('http://localhost', server: ['HTTP_ORIGIN' => 'test.com']);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testWildcardDomainMatching(): void
    {
        $request = Request::create('http://localhost', server: ['HTTP_REFERER' => 'https://subdomain.test.com']);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testRequestsWithoutRefererOrOrigin(): void
    {
        $request = Request::create('http://localhost');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testStatefulDomainsCanBeResolvedUsingCallback(): void
    {
        $request = Request::create('http://localhost', server: ['HTTP_REFERER' => 'https://custom.example.com']);

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));

        EnsureFrontendRequestsAreStateful::resolveStatefulDomainsUsing(
            fn (Request $request): array => ['custom.example.com']
        );

        try {
            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
        } finally {
            EnsureFrontendRequestsAreStateful::flushState();
        }
    }

    public function testStatefulDomainsResolverReceivesCurrentRequest(): void
    {
        $request = Request::create('http://api.example.com', server: ['HTTP_REFERER' => 'https://frontend.example.com']);

        EnsureFrontendRequestsAreStateful::resolveStatefulDomainsUsing(function (Request $request): array {
            $this->assertSame('api.example.com', $request->getHost());

            return ['frontend.example.com'];
        });

        try {
            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
        } finally {
            EnsureFrontendRequestsAreStateful::flushState();
        }
    }

    public function testStatefulDomainsResolverCanBeCleared(): void
    {
        $request = Request::create('http://localhost', server: ['HTTP_REFERER' => 'https://custom.example.com']);

        EnsureFrontendRequestsAreStateful::resolveStatefulDomainsUsing(
            fn (Request $request): array => ['custom.example.com']
        );

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));

        EnsureFrontendRequestsAreStateful::flushState();

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testMiddlewareDoesNotMutateSessionConfig(): void
    {
        $this->app->make('config')->set([
            'session.http_only' => false,
            'session.same_site' => 'strict',
        ]);

        $request = Request::create('http://localhost', server: ['HTTP_ORIGIN' => 'https://wrong.com']);

        (new EnsureFrontendRequestsAreStateful)->handle($request, fn () => new Response('ok'));

        $this->assertFalse($this->app->make('config')->get('session.http_only'));
        $this->assertSame('strict', $this->app->make('config')->get('session.same_site'));
    }
}
