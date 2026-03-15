<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Support\ServiceProvider;

/**
 * Service provider for the testing package.
 */
class TestingServiceProvider extends ServiceProvider
{
    /**
     * Register testing services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->app->singleton(ParallelTesting::class, fn ($app) => new ParallelTesting($app));
        }
    }
}
