<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Testing\ParallelTestingServiceProvider;

class ParallelTestingCacheTest extends TestCase
{
    /**
     * Get the service providers for the test application.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [ParallelTestingServiceProvider::class, ParallelCacheResolvingProvider::class];
    }

    /**
     * Configure the cache store.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('cache.prefix', 'myapp_cache_');
        $app->make('config')->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache']);
    }

    /**
     * Configure the parallel worker token.
     */
    protected function withParallelTesting(ApplicationContract $app): void
    {
        $app->make(ParallelTesting::class)->resolveTokenUsing(fn () => '7');
    }

    /**
     * Configure a test application without a parallel worker token.
     */
    protected function withoutParallelTesting(ApplicationContract $app): void
    {
        $app->make(ParallelTesting::class)->resolveTokenUsing(fn () => false);
    }

    /**
     * Disable parallel cache isolation for the test application.
     */
    protected function withoutCacheIsolation(ApplicationContract $app): void
    {
        $this->withParallelTesting($app);
        $app->make(ParallelTesting::class)->resolveOptionsUsing(fn (string $option) => $option === 'without_cache');
    }

    #[DefineEnvironment('withParallelTesting')]
    public function testStoreResolvedInProviderBootUsesIsolatedPrefix(): void
    {
        $this->assertCachePrefix('myapp_cache_test_7_');

        $this->refreshApplication();

        $this->assertCachePrefix('myapp_cache_test_7_');
    }

    #[DefineEnvironment('withoutParallelTesting')]
    public function testStoreResolvedInProviderBootKeepsPrefixOutsideParallelTesting(): void
    {
        $this->assertCachePrefix('myapp_cache_');
    }

    #[DefineEnvironment('withoutCacheIsolation')]
    public function testStoreResolvedInProviderBootKeepsPrefixWhenOptedOut(): void
    {
        $this->assertCachePrefix('myapp_cache_');
    }

    /**
     * Assert that the original store resolved during boot has the expected prefix.
     */
    protected function assertCachePrefix(string $prefix): void
    {
        $store = $this->app->make('cache')->store('database')->getStore();

        $this->assertSame($store, $this->app->make('cache.resolved.during.boot'));
        $this->assertSame($prefix, $store->getPrefix());
        $this->assertSame($prefix, config('cache.prefix'));
    }
}

class ParallelCacheResolvingProvider extends ServiceProvider
{
    /**
     * Resolve the cache store during provider boot.
     */
    public function boot(): void
    {
        $this->app->instance('cache.resolved.during.boot', $this->app->make('cache')->store('database')->getStore());
    }
}
