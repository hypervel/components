<?php

declare(strict_types=1);

namespace Hypervel\Devtool;

use Hypervel\Devtool\Generator\BatchesTableCommand;
use Hypervel\Devtool\Generator\CacheLocksTableCommand;
use Hypervel\Devtool\Generator\CacheTableCommand;
use Hypervel\Devtool\Generator\NotificationTableCommand;
use Hypervel\Devtool\Generator\QueueFailedTableCommand;
use Hypervel\Devtool\Generator\QueueTableCommand;
use Hypervel\Devtool\Generator\SessionTableCommand;
use Hypervel\Support\ServiceProvider;

class DevtoolServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->commands([
            BatchesTableCommand::class,
            CacheLocksTableCommand::class,
            CacheTableCommand::class,
            NotificationTableCommand::class,
            QueueFailedTableCommand::class,
            QueueTableCommand::class,
            SessionTableCommand::class,
        ]);
    }
}
