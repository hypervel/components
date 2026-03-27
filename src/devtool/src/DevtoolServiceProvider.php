<?php

declare(strict_types=1);

namespace Hypervel\Devtool;

use Hypervel\Devtool\Generator\NotificationTableCommand;
use Hypervel\Support\ServiceProvider;

class DevtoolServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->commands([
            NotificationTableCommand::class,
        ]);
    }
}
