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
