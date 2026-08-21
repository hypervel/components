<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Http\Request;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use InvalidArgumentException;

class RoutingServiceProviderTest extends TestCase
{
    public function testUrlGeneratorUsesRequestSchemeWhenForceHttpsIsDisabled(): void
    {
        $this->app->instance('request', Request::create('http://example.com/'));

        $this->assertSame('http://example.com/foo', $this->app->make('url')->to('foo'));
    }

    public function testUrlGeneratorRequiresForceHttpsConfiguration(): void
    {
        $this->app->make('config')->set('app', [
            'key' => $this->app->make('config')->get('app.key'),
        ]);

        $this->app->instance('request', Request::create('http://example.com/'));

        $this->expectExceptionObject(new InvalidArgumentException(
            'Configuration value for key [app.force_https] must be a boolean, NULL given.'
        ));

        $this->app->make('url');
    }

    #[WithConfig('app.force_https', true)]
    public function testUrlGeneratorForcesHttpsWhenConfigured(): void
    {
        $this->app->instance('request', Request::create('http://example.com/'));

        $this->assertSame('https://example.com/foo', $this->app->make('url')->to('foo'));
    }

    public function testRebindingTheContainerRequestDoesNotMutateTheUrlGeneratorFallbackRequest(): void
    {
        $original = Request::create('http://original.example/');
        $replacement = Request::create('https://replacement.example/');
        $this->app->instance('request', $original);

        $url = $this->app->make('url');

        $this->app->instance('request', $replacement);

        $this->assertSame($original, $url->getRequest());
    }
}
