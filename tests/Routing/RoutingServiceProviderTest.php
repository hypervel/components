<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Http\Request;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;

class RoutingServiceProviderTest extends TestCase
{
    public function testUrlGeneratorUsesRequestSchemeWhenForceHttpsIsDisabled(): void
    {
        $this->app->instance('request', Request::create('http://example.com/'));

        $this->assertSame('http://example.com/foo', $this->app->make('url')->to('foo'));
    }

    public function testUrlGeneratorUsesRequestSchemeWhenForceHttpsConfigIsMissing(): void
    {
        $this->app->make('config')->set('app', [
            'key' => $this->app->make('config')->get('app.key'),
        ]);

        $this->app->instance('request', Request::create('http://example.com/'));

        $this->assertSame('http://example.com/foo', $this->app->make('url')->to('foo'));
    }

    #[WithConfig('app.force_https', true)]
    public function testUrlGeneratorForcesHttpsWhenConfigured(): void
    {
        $this->app->instance('request', Request::create('http://example.com/'));

        $this->assertSame('https://example.com/foo', $this->app->make('url')->to('foo'));
    }
}
