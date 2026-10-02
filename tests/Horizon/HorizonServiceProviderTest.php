<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Sentinel\Http\Middleware\SentinelMiddleware;
use Hypervel\Testbench\TestCase;

class HorizonServiceProviderTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('horizon.middleware', ['web', 'auth']);
    }

    public function testDashboardRoutesRunSentinelBeforeTheConfiguredMiddleware(): void
    {
        $this->assertSame(
            [SentinelMiddleware::class . ':horizon', 'web', 'auth'],
            $this->app->make('router')->getMiddlewareGroups()['horizon'],
        );
    }

    public function testLocalDashboardRejectsRequestsForwardedForPublicIps(): void
    {
        $this->app->instance('env', 'local');
        TrustProxies::at('*');

        $this->withHeaders(['X-Forwarded-For' => '202.168.65.217'])
            ->get('/horizon')
            ->assertUnauthorized();
    }
}
