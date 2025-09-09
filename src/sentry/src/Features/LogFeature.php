<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Sentry\LogChannel;
use Hypervel\Support\Facades\Log;
use Hypervel\Sentry\LogAggregator\LogChannel as LogAggregatorChannel;

class LogFeature extends Feature
{
    public function isApplicable(): bool
    {
        return true;
    }

    public function register(): void
    {
        Log::extend('sentry', function ($app, array $config) {
            return (new LogChannel($app))($config);
        });

        Log::extend('sentry_aggregator', function ($app, array $config) {
            return (new LogAggregatorChannel($app))($config);
        });
    }
}
