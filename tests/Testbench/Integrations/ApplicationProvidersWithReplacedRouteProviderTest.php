<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Routing\Router;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class ApplicationProvidersWithReplacedRouteProviderTest extends TestCase
{
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
