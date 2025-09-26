<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Sentry\LogChannel;
use Hypervel\Sentry\Logs\LogChannel as LogsLogChannel;
use Hypervel\Support\Facades\Log;

class LogFeature extends Feature
{
    public function isApplicable(): bool
    {
        return $this->switcher->isTracingEnable('logs')
            || $this->switcher->isBreadcrumbEnable('logs');
    }

    public function register(): void
    {
        Log::extend('sentry', function ($app, array $config) {
            return (new LogChannel($app))($config);
        });

        Log::extend('sentry_logs', function ($app, array $config) {
            return (new LogsLogChannel($app))($config);
        });
    }
}
