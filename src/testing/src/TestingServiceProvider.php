<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Support\AggregateServiceProvider;
use Hypervel\Testing\Console\TestCommand;

class TestingServiceProvider extends AggregateServiceProvider
{
    /**
     * The provider class names.
     *
     * @var array<int, class-string<\Hypervel\Support\ServiceProvider>>
     */
    protected array $providers = [
        ParallelTestingServiceProvider::class,
    ];

    /**
     * Bootstrap testing services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestCommand::class,
            ]);
        }
    }
}
