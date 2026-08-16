<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Http\Request;
use Hypervel\Routing\RoutingServiceProvider;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use InvalidArgumentException;
use ReflectionClass;

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

    public function testReloadConfigurationUpdatesRetainedUrlGeneratorInBothHttpsDirections(): void
    {
        $url = $this->app->make('url');
        $provider = $this->app->getProvider(RoutingServiceProvider::class);
        $reflection = new ReflectionClass($url);
        $routes = $reflection->getProperty('routes')->getValue($url);

        config([
            'app.url' => 'http://refreshed.example',
            'app.asset_url' => 'https://assets.example',
            'app.force_https' => true,
        ]);

        $provider->reloadConfiguration();

        $this->assertSame($url, $this->app->make('url'));
        $this->assertSame($routes, $reflection->getProperty('routes')->getValue($url));
        $this->assertSame(
            'http://refreshed.example',
            $reflection->getProperty('request')->getValue($url)->root(),
        );
        $this->assertSame('https://assets.example/image.png', $url->asset('image.png'));
        $this->assertSame('https://refreshed.example/path', $url->to('path'));

        config([
            'app.url' => 'http://second.example',
            'app.asset_url' => null,
            'app.force_https' => false,
        ]);

        $provider->reloadConfiguration();

        $this->assertSame('http://second.example/image.png', $url->asset('image.png'));
        $this->assertSame('http://second.example/path', $url->to('path'));
    }
}
