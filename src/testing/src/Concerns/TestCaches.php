<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Support\Facades\ParallelTesting;

trait TestCaches
{
    /**
     * Boot test cache for parallel testing.
     */
    protected function bootTestCache(): void
    {
        ParallelTesting::setUpTestCase(function () {
            if (ParallelTesting::option('without_cache')) {
                return;
            }

            $this->switchToCachePrefix($this->parallelSafeCachePrefix());
        });
    }

    /**
     * Get the test cache prefix.
     */
    protected function parallelSafeCachePrefix(): string
    {
        $token = ParallelTesting::token();
        $suffix = "test_{$token}_";
        $prefix = $this->app['config']->get('cache.prefix', '');

        return str_ends_with($prefix, $suffix)
            ? $prefix
            : $prefix . $suffix;
    }

    /**
     * Switch to the given cache prefix.
     */
    protected function switchToCachePrefix(string $prefix): void
    {
        $this->app['config']->set('cache.prefix', $prefix);
    }
}
