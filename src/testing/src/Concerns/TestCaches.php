<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\Facades\ParallelTesting;

trait TestCaches
{
    /**
     * Boot test cache for parallel testing.
     */
    protected function bootTestCache(): void
    {
        $isolate = function () {
            if (! ParallelTesting::inParallel() || ParallelTesting::option('without_cache')) {
                return;
            }

            $this->switchToCachePrefix($this->parallelSafeCachePrefix());

            // Laravel's limiter uses the cache, so without_cache governs both prefixes.
            $config = $this->app->make('config');
            $config->set('rate-limiter.prefix', $this->parallelSafePrefix($config->string('rate-limiter.prefix')));
        };

        // Earlier application booting callbacks can resolve stores before ours runs.
        $this->app->resolving('cache', $isolate);
        $this->app->resolving(RateLimiter::class, $isolate);
        $this->app->booting($isolate);
    }

    /**
     * Get the test cache prefix.
     */
    protected function parallelSafeCachePrefix(): string
    {
        return $this->parallelSafePrefix($this->app->make('config')->string('cache.prefix'));
    }

    /**
     * Append the parallel worker token to a prefix once.
     */
    protected function parallelSafePrefix(string $prefix): string
    {
        $token = ParallelTesting::token();
        $suffix = "test_{$token}_";

        return str_ends_with($prefix, $suffix)
            ? $prefix
            : $prefix . $suffix;
    }

    /**
     * Switch to the given cache prefix.
     */
    protected function switchToCachePrefix(string $prefix): void
    {
        $this->app->make('config')->set('cache.prefix', $prefix);
    }
}
