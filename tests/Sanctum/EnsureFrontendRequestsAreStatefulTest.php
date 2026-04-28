<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Http\Request;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Testbench\TestCase;

class EnsureFrontendRequestsAreStatefulTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('sanctum.stateful', ['test.com', '*.test.com']);
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

    public function testStatefulDomainsReturnsConfiguredDomains(): void
    {
        $domains = EnsureFrontendRequestsAreStateful::statefulDomains();

        $this->assertIsArray($domains);
        $this->assertContains('test.com', $domains);
        $this->assertContains('*.test.com', $domains);
    }

    public function testStatefulDomainsCanBeOverridden(): void
    {
        $request = Request::create('http://localhost', server: ['HTTP_REFERER' => 'https://custom.example.com']);

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
