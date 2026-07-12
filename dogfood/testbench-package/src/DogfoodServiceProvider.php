<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\TestbenchPackage;

use Hypervel\Support\ServiceProvider;

class DogfoodServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->make('config')->set('dogfood.package_provider_loaded', true);
        $this->commands([DogfoodProbeCommand::class]);
    }
}
