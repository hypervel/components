<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Http\Request;
use Hypervel\Inertia\Middleware\EnsureGetOnRedirect;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\RateLimiter;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\ExampleMiddleware;

/**
 * @internal
 * @coversNothing
 */
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
        /** @var Kernel $kernel */
        $kernel = $this->app->make(HttpKernelContract::class);

        $this->assertTrue($kernel->hasMiddleware(EnsureGetOnRedirect::class));
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
