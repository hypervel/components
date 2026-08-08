<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Request;
use Hypervel\Inertia\InertiaServiceProvider;
use Hypervel\Inertia\Middleware\EnsureGetOnRedirect;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\RateLimiter;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\ExampleMiddleware;
use Hypervel\View\ViewFinderInterface;
use InvalidArgumentException;
use Mockery as m;

class InertiaServiceProviderTest extends TestCase
{
    public function testBladeDirectiveIsRegistered(): void
    {
        $this->assertArrayHasKey('inertia', Blade::getCustomDirectives());
    }

    public function testRequestMacroIsRegistered(): void
    {
        $request = Request::create('/user/123', 'GET');

        $this->assertFalse($request->inertia());

        $request->headers->add(['X-Inertia' => 'true']);

        $this->assertTrue($request->inertia());
    }

    public function testRouteMacroIsRegistered(): void
    {
        $route = Route::inertia('/', 'User/Edit', ['user' => ['name' => 'Jonathan']]);
        $routes = Route::getRoutes();

        $this->assertNotEmpty($routes->getRoutes());

        $inertiaRoute = collect($routes->getRoutes())->first(fn ($route) => $route->uri === '/');

        $this->assertEquals($route, $inertiaRoute);
        $this->assertEquals(['GET', 'HEAD'], $inertiaRoute->methods);
        $this->assertEquals('/', $inertiaRoute->uri);
        $this->assertEquals(['uses' => '\Hypervel\Inertia\Controller@__invoke', 'controller' => '\Hypervel\Inertia\Controller'], $inertiaRoute->action);
        $this->assertEquals(['component' => 'User/Edit', 'props' => ['user' => ['name' => 'Jonathan']]], $inertiaRoute->defaults);
    }

    public function testEnsureGetOnRedirectMiddlewareIsRegisteredGlobally(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);

        $this->assertTrue($kernel->hasMiddleware(EnsureGetOnRedirect::class));
    }

    public function testGatewayContractResolvesTheConcreteWorkerInstance(): void
    {
        $this->assertSame(
            $this->app->make(HttpGateway::class),
            $this->app->make(Gateway::class),
        );
    }

    public function testInertiaViewFinderIsSharedAndCanBeRebound(): void
    {
        $finder = $this->app->make('inertia.view-finder');

        $this->assertSame($finder, $this->app->make('inertia.view-finder'));

        $replacement = m::mock(ViewFinderInterface::class);
        $this->app->bind('inertia.view-finder', fn () => $replacement);

        $this->assertSame($replacement, $this->app->make('inertia.view-finder'));
    }

    public function testInertiaViewFinderReusesSuccessfulLookupsWithConfiguredPathsAndExtensions(): void
    {
        config()->set('inertia.pages.paths', ['/inertia-pages']);
        config()->set('inertia.pages.extensions', ['vue']);

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->once()->with('/inertia-pages/Dashboard.vue')->andReturn(true);
        $this->app->instance('files', $files);

        $finder = $this->app->make('inertia.view-finder');

        $this->assertSame('/inertia-pages/Dashboard.vue', $finder->find('Dashboard'));
        $this->assertSame('/inertia-pages/Dashboard.vue', $finder->find('Dashboard'));
    }

    public function testInertiaViewFinderDoesNotCacheMisses(): void
    {
        config()->set('inertia.pages.paths', ['/inertia-pages']);
        config()->set('inertia.pages.extensions', ['vue']);

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->twice()->with('/inertia-pages/Missing.vue')->andReturn(false);
        $this->app->instance('files', $files);

        $finder = $this->app->make('inertia.view-finder');

        foreach (range(1, 2) as $_) {
            try {
                $finder->find('Missing');
                $this->fail('Expected the missing component lookup to fail.');
            } catch (InvalidArgumentException) {
                // A failed lookup must query the filesystem again next time.
            }
        }
    }

    public function testRedirectMiddlewareRegistersThroughTheKernelContract(): void
    {
        $kernel = m::mock(HttpKernelContract::class);
        $kernel->shouldReceive('pushMiddleware')
            ->once()
            ->with(EnsureGetOnRedirect::class)
            ->andReturnSelf();
        $this->app->instance(HttpKernelContract::class, $kernel);

        (new InspectableInertiaServiceProvider($this->app))
            ->pushRedirectMiddlewareForTest();
    }

    public function testRedirectResponseFromRateLimiterIsConvertedTo303(): void
    {
        RateLimiter::for('api', fn () => Limit::perMinute(1)->response(fn () => back()));

        // Needed for the web middleware
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        Route::middleware(['web', ExampleMiddleware::class, 'throttle:api'])
            ->delete('/foo', fn () => 'ok');

        $this
            ->from('/bar')
            ->delete('/foo', [], ['X-Inertia' => 'true'])
            ->assertOk();

        $this
            ->from('/bar')
            ->delete('/foo', [], ['X-Inertia' => 'true'])
            ->assertRedirect('/bar')
            ->assertStatus(303);
    }
}

class InspectableInertiaServiceProvider extends InertiaServiceProvider
{
    /**
     * Register redirect middleware for inspection.
     */
    public function pushRedirectMiddlewareForTest(): void
    {
        $this->pushRedirectMiddleware();
    }
}
