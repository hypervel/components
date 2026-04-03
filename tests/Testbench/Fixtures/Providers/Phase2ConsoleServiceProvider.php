<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures\Providers;

use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Foundation\Console\PurgeSkeletonCommand;
use Hypervel\Testbench\Foundation\Console\SyncSkeletonCommand;

class Phase2ConsoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeSkeletonCommand::class,
                SyncSkeletonCommand::class,
            ]);
        }
    }
}
