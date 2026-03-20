<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Support\ServiceProvider;

class TestbenchServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Foundation\Console\CreateSqliteDbCommand::class,
                Foundation\Console\DropSqliteDbCommand::class,
            ]);
        }
    }
}
