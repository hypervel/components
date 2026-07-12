<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Hypervel\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->make('config')->set('dogfood.workbench_provider_loaded', true);
    }
}
