<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Support\ServiceProvider;
use Hypervel\Testing\Concerns\TestCaches;
use Hypervel\Testing\Concerns\TestDatabases;
use Hypervel\Testing\Concerns\TestViews;

class ParallelTestingServiceProvider extends ServiceProvider
{
    use TestCaches;
    use TestDatabases;
    use TestViews;

    /**
     * Register testing services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->app->singleton(ParallelTesting::class, fn ($app) => new ParallelTesting($app));
        }
    }

    /**
     * Bootstrap testing services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->bootTestCache();
            $this->bootTestDatabase();
            $this->bootTestViews();
        }
    }
}
