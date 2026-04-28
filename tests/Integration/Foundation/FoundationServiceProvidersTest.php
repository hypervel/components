<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

class FoundationServiceProvidersTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HeadServiceProvider::class];
    }

    public function testItCanBootServiceProviderRegisteredFromAnotherServiceProvider()
    {
        $this->assertTrue($this->app['tail.registered']);
        $this->assertTrue($this->app['tail.booted']);
    }
}

class HeadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->app->register(TailServiceProvider::class);
    }
}

class TailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['tail.registered'] = true;
    }

    public function boot(): void
    {
        $this->app['tail.booted'] = true;
    }
}
