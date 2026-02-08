<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class EnsureFrontendRequestsAreStatefulTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get('config')->set('sanctum.stateful', ['test.com', '*.test.com']);
    }

    public function testRequestFromFrontendIsIdentified(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn('https://test.com');
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn(null);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testRequestNotFromFrontend(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn('https://wrong.com');
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn(null);

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testOriginFallback(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn(null);
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn('test.com');

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testWildcardDomainMatching(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn('https://subdomain.test.com');
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn(null);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testRequestsWithoutRefererOrOrigin(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn(null);
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn(null);

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testStatefulDomainsReturnsConfiguredDomains(): void
    {
        $domains = EnsureFrontendRequestsAreStateful::statefulDomains();

        $this->assertIsArray($domains);
        $this->assertContains('test.com', $domains);
        $this->assertContains('*.test.com', $domains);
    }

    public function testStatefulDomainsCanBeOverridden(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn('https://custom.example.com');
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn(null);

        // Default middleware should NOT match custom domain
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));

        // Custom middleware with overridden statefulDomains SHOULD match
        $this->assertTrue(CustomStatefulMiddleware::fromFrontend($request));
    }
}

/**
 * Custom middleware for testing statefulDomains override.
 */
class CustomStatefulMiddleware extends EnsureFrontendRequestsAreStateful
{
    public static function statefulDomains(): array
    {
        return ['custom.example.com'];
    }
}
