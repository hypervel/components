<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

use Hypervel\Support\ServiceProvider;

class WayfinderServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCommand::class,
            ]);
        }
    }
}
