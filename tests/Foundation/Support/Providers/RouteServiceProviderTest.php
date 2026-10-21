<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Support\Providers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;

class RouteServiceProviderTest extends TestCase
{
    /**
     * The route groups loaded while the application booted, in order.
     *
     * @var list<string>
     */
    public static array $loaded = [];

    protected function setUp(): void
    {
        static::$loaded = [];

        parent::setUp();
    }

    /**
     * Resolve application implementation.
     */
    protected function resolveApplication(): ApplicationContract
    {
        return Application::configure(static::applicationBasePath())
            ->withRouting(using: function (): void {
                static::$loaded[] = 'application';

                Route::any('{path}', fn () => 'application')->where('path', '.*');
            })
            ->withMiddleware(function (Middleware $middleware): void {
            })
            ->withExceptions(function (Exceptions $exceptions): void {
            })
            ->create();
    }

    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            WebhookRoutesServiceProvider::class,
            ReceiverPatternServiceProvider::class,
        ];
    }

    /**
     * Treat the application's routes as cached.
     */
    protected function useCachedRoutes(ApplicationContract $app): void
    {
        $app->instance('routes.cached', true);

        RouteServiceProvider::loadCachedRoutesUsing(static function (): void {
            static::$loaded[] = 'cached';
        });
    }

    public function testRoutesLoadedBeforeTheApplicationRoutesMatchFirstAndUseLaterPatterns(): void
    {
        $this->post('webhooks/github')->assertOk()->assertContent('webhook github');

        // The pattern was registered after the callback, by a provider that booted later.
        $this->post('webhooks/123')->assertOk()->assertContent('application');
    }

    #[DefineEnvironment('useCachedRoutes')]
    public function testCachedRoutesSkipTheCallbacks(): void
    {
        $this->assertSame(['cached'], static::$loaded);
    }

    public function testCallbacksRunInRegistrationOrderBeforeTheApplicationRoutes(): void
    {
        $this->assertSame(['first callback', 'second callback', 'application'], static::$loaded);
    }
}

class WebhookRoutesServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RouteServiceProvider::beforeLoadingRoutes(function (Router $router): void {
            RouteServiceProviderTest::$loaded[] = 'first callback';

            $router->post('webhooks/{receiver}', fn (string $receiver) => "webhook {$receiver}");
        });

        RouteServiceProvider::beforeLoadingRoutes(function (): void {
            RouteServiceProviderTest::$loaded[] = 'second callback';
        });
    }
}

class ReceiverPatternServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::pattern('receiver', '[a-z]+');
    }
}
