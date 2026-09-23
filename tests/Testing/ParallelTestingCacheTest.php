<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Testing\ParallelTestingServiceProvider;

class ParallelTestingCacheTest extends TestCase
{
    protected bool $resolveRateLimiterFirst = false;

    /**
     * Get the service providers for the test application.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        $app->booting(function () use ($app): void {
            if ($this->resolveRateLimiterFirst) {
                $app->instance('limiter.resolved.during.booting', $app->make(RateLimiter::class)->store('worker-array'));
            }

            $app->instance('cache.resolved.during.booting', $app->make('cache')->store('database')->getStore());

            if (! $this->resolveRateLimiterFirst) {
                $app->instance('limiter.resolved.during.booting', $app->make(RateLimiter::class)->store('worker-array'));
            }
        });

        return [ParallelTestingServiceProvider::class, ParallelCacheResolvingProvider::class];
    }

    /**
     * Configure the cache store.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('cache.prefix', 'myapp_cache_');
        $app->make('config')->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache']);
        $app->make('config')->set('rate-limiter.prefix', 'myapp_limiter_');
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
     * Resolve the rate limiter before the cache during application booting.
     */
    protected function withRateLimiterFirst(ApplicationContract $app): void
    {
        $this->withParallelTesting($app);
        $this->resolveRateLimiterFirst = true;
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
    public function testStoresResolvedDuringBootUseIsolatedPrefixes(): void
    {
        $this->assertPrefixes('test_7_');

        $this->refreshApplication();

        $this->assertPrefixes('test_7_');
    }

    #[DefineEnvironment('withRateLimiterFirst')]
    public function testRateLimiterResolvedBeforeCacheUsesIsolatedPrefix(): void
    {
        $this->assertPrefixes('test_7_');
    }

    #[DefineEnvironment('withoutParallelTesting')]
    public function testStoreResolvedInProviderBootKeepsPrefixOutsideParallelTesting(): void
    {
        $this->assertPrefixes('');
    }

    #[DefineEnvironment('withoutCacheIsolation')]
    public function testStoreResolvedInProviderBootKeepsPrefixWhenOptedOut(): void
    {
        $this->assertPrefixes('');
    }

    /**
     * Assert that stores resolved during boot captured the expected prefixes.
     */
    protected function assertPrefixes(string $suffix): void
    {
        $store = $this->app->make('cache')->store('database')->getStore();

        $this->assertSame($store, $this->app->make('cache.resolved.during.boot'));
        $this->assertSame($store, $this->app->make('cache.resolved.during.booting'));
        $this->assertSame('myapp_cache_' . $suffix, $store->getPrefix());
        $this->assertSame('myapp_cache_' . $suffix, config('cache.prefix'));

        $limiter = $this->app->make(RateLimiter::class)->store('worker-array');
        $this->assertSame($limiter, $this->app->make('limiter.resolved.during.booting'));
        $this->assertSame('myapp_limiter_' . $suffix, config('rate-limiter.prefix'));

        $policy = Limit::perMinute(1)->by('user');
        $this->assertTrue($limiter->consume($policy)->allowed());
        $key = (new KeyResolver('myapp_limiter_' . $suffix))->resolve($policy);
        $this->assertTrue($limiter->getStore()->inspect($key, $policy)->denied());
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
