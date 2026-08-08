<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;

class SanctumRoutesTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
        ];
    }

    protected function useCustomPrefix(ApplicationContract $app): void
    {
        $app->make('config')->set('sanctum.prefix', 'auth');
    }

    protected function disableRoutes(ApplicationContract $app): void
    {
        $app->make('config')->set('sanctum.routes', false);
    }

    public function testCsrfCookieRouteUsesTheExpectedNameUriAndMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('sanctum.csrf-cookie');

        $this->assertNotNull($route);
        $this->assertSame('sanctum/csrf-cookie', $route->uri);
        $this->assertSame(['web'], $route->middleware());
        $this->assertContains('GET', $route->methods());
    }

    #[DefineEnvironment('useCustomPrefix')]
    public function testCsrfCookieRouteUsesTheConfiguredPrefix(): void
    {
        $route = Route::getRoutes()->getByName('sanctum.csrf-cookie');

        $this->assertNotNull($route);
        $this->assertSame('auth/csrf-cookie', $route->uri);
    }

    #[DefineEnvironment('disableRoutes')]
    public function testCsrfCookieRouteCanBeDisabled(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('sanctum.csrf-cookie'));
    }
}
