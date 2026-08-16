<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Core\Logger\StdoutLogger;
use Hypervel\Support\ServiceProvider;

class LogServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('log', fn ($app) => new LogManager($app));
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use replaces shared logging configuration while
     * concurrent coroutines may still be writing through existing loggers.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved('log')) {
            $this->app->make('log')->forgetChannels();
        }

        if (! $this->app->resolved(StdoutLoggerInterface::class)) {
            return;
        }

        $logger = $this->app->make(StdoutLoggerInterface::class);

        if ($logger instanceof StdoutLogger) {
            $logger->reloadConfiguration();
        }
    }
}
