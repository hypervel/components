<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Support\Facades\ParallelTesting;

trait TestCaches
{
    /**
     * The original cache prefix prior to appending the token.
     */
    protected static ?string $originalCachePrefix = null;

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
        self::$originalCachePrefix ??= $this->app['config']->get('cache.prefix', '');

        return self::$originalCachePrefix . 'test_' . ParallelTesting::token() . '_';
    }

    /**
     * Switch to the given cache prefix.
     */
    protected function switchToCachePrefix(string $prefix): void
    {
        $this->app['config']->set('cache.prefix', $prefix);
    }
}
