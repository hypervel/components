<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Routing\Router;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class ApplicationProvidersWithReplacedRouteProviderTest extends TestCase
{
    /**
     * Get application providers.
     */
    protected function getApplicationProviders(ApplicationContract $app): array
    {
        return [...parent::getApplicationProviders($app), ReplacementRouteServiceProvider::class];
    }

    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [ReplacementPackageRoutesServiceProvider::class];
    }

    #[Override]
    protected function overrideApplicationProviders(ApplicationContract $app): array
    {
        return [
            RouteServiceProvider::class => ReplacementRouteServiceProvider::class,
        ];
    }

    #[Test]
    public function itRegistersTheReplacementRouteProvider(): void
    {
        $this->assertSame(
            [ReplacementRouteServiceProvider::class],
            array_keys($this->app->getProviders(RouteServiceProvider::class))
        );

        $this->get('replacement')->assertOk()->assertContent('replacement');
        $this->get('replacement-package')->assertOk()->assertContent('package');
    }
}

class ReplacementPackageRoutesServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        RouteServiceProvider::beforeLoadingRoutes(function (Router $router): void {
            $router->get('replacement-package', fn () => 'package');
        });
    }
}

class ReplacementRouteServiceProvider extends RouteServiceProvider
{
    /**
     * Define the routes for the application.
     */
    public function map(Router $router): void
    {
        $router->get('replacement', fn () => 'replacement');
    }
}
