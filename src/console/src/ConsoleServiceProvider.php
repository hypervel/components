<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Console\Commands\ScheduleClearCacheCommand;
use Hypervel\Console\Commands\ScheduleListCommand;
use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Commands\ScheduleStopCommand;
use Hypervel\Console\Commands\ScheduleTestCommand;
use Hypervel\Support\ServiceProvider;

class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->commands([
            ScheduleClearCacheCommand::class,
            ScheduleListCommand::class,
            ScheduleRunCommand::class,
            ScheduleStopCommand::class,
            ScheduleTestCommand::class,
        ]);
    }
}
