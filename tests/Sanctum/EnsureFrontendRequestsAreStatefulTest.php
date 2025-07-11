<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Testbench\TestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class EnsureFrontendRequestsAreStatefulTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('sanctum.stateful', ['test.com', '*.test.com']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        Mockery::close();
    }

    public function testRequestFromFrontendIsIdentified(): void
    {
        $request = Mockery::mock(RequestInterface::class);
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
        $request = Mockery::mock(RequestInterface::class);
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
        $request = Mockery::mock(RequestInterface::class);
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
        $request = Mockery::mock(RequestInterface::class);
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
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn(null);
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn(null);

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }
}