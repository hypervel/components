<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Support\Providers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Assert;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('filesystems.disks.local.serve', false)]
class RouteServiceProviderTest extends TestCase
{
    /**
     * Resolve application implementation.
     */
    protected function resolveApplication(): ApplicationContract
    {
        return Application::configure(static::applicationBasePath())
            ->withProviders([
                AppRouteServiceProvider::class,
            ])
            ->withRouting(
                using: function () {
                    Route::get('login', fn () => 'Login')->name('login');
                }
            )
            ->withMiddleware(function (Middleware $middleware) {
            })
            ->withExceptions(function (Exceptions $exceptions) {
            })->create();
    }

    public function testItCanRegisterMultipleRouteServiceProviders()
    {
        Assert::assertArraySubset([
            RouteServiceProvider::class => true,
            AppRouteServiceProvider::class => true,
        ], $this->app->getLoadedProviders());
    }

    public function testItCanUsesRoutesRegisteredUsingBootstrapFile()
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Login');
    }

    public function testItCanUsesRoutesRegisteredUsingConfigurationFile()
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hello');
    }
}

class AppRouteServiceProvider extends RouteServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->routes(function () {
            Route::get('dashboard', fn () => 'Hello')->name('dashboard');
        });
    }
}
