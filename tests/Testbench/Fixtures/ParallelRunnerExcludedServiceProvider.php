<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures;

use Hypervel\Support\ServiceProvider;

class ParallelRunnerExcludedServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->instance('parallel-runner.excluded-provider', true);
    }
}
