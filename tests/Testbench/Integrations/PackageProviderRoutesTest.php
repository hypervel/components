<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Routing\Router;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PackageProviderRoutesTest extends TestCase
{
    /**
     * Get application providers.
     */
    protected function getApplicationProviders(ApplicationContract $app): array
    {
        // An explicit entry must not move the route provider ahead of package providers.
        return [...parent::getApplicationProviders($app), RouteServiceProvider::class];
    }

    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [PackageRoutesServiceProvider::class];
    }

    #[Test]
    public function itLoadsRoutesThatPackageProvidersRegisterBeforeRouteLoading(): void
    {
        $this->get('package-route')->assertOk()->assertContent('package');
    }
}

class PackageRoutesServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RouteServiceProvider::beforeLoadingRoutes(function (Router $router): void {
            $router->get('package-route', fn () => 'package');
        });
    }
}
