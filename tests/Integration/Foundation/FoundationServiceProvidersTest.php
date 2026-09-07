<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

class FoundationServiceProvidersTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [HeadServiceProvider::class];
    }

    public function testItCanBootServiceProviderRegisteredFromAnotherServiceProvider(): void
    {
        $this->assertTrue($this->app->make('tail.registered'));
        $this->assertTrue($this->app->make('tail.booted'));
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
        $this->app->instance('tail.registered', true);
    }

    public function boot(): void
    {
        $this->app->instance('tail.booted', true);
    }
}
