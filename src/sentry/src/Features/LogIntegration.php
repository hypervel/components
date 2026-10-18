<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Sentry\LogChannel;
use Hypervel\Sentry\Logs\LogChannel as LogsLogChannel;
use Hypervel\Support\Facades\Log;
use Monolog\Logger;

class LogIntegration extends Feature
{
    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        return true;
    }

    /**
     * Register the feature in the environment.
     */
    public function register(): void
    {
        Log::extend('sentry', function (Application $app, array $config): Logger {
            return (new LogChannel($app))($config);
        });

        Log::extend('sentry_logs', function (Application $app, array $config): Logger {
            return (new LogsLogChannel($app))($config);
        });
    }
}
