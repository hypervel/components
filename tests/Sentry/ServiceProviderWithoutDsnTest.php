<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Routing\Events\RouteMatched;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Sentry\State\HubInterface;

/**
 * @internal
 * @coversNothing
 */
class ServiceProviderWithoutDsnTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('sentry.dsn', null);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SentryServiceProvider::class,
        ];
    }

    public function testIsBound(): void
    {
        $this->assertTrue(app()->bound('sentry'));
    }

    public function testDsnIsNotSet(): void
    {
        $this->assertNull(app('sentry')->getClient()->getOptions()->getDsn());
    }

    public function testDidNotRegisterEvents(): void
    {
        $this->assertFalse(app('events')->hasListeners(RouteMatched::class));
    }

    public function testArtisanCommandsAreRegistered(): void
    {
        $this->assertArrayHasKey('sentry:test', Artisan::all());
        $this->assertArrayHasKey('sentry:publish', Artisan::all());
    }
}
