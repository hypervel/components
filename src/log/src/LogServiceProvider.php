<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Support\ServiceProvider;
use Monolog\Logger as Monolog;

class LogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('log', fn ($app) => new LogManager($app));
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->app->make('events')->listen(BeforeServerFork::class, function (): void {
            if (! $this->app->resolved('log')) {
                return;
            }

            /** @var LogManager $log */
            $log = $this->app->make('log');

            // Closing flushes buffered records once, instead of from every forked process, and
            // workers then create their own handlers rather than share the server's.
            foreach ($log->getChannels() as $name => $channel) {
                $logger = $channel->getLogger();

                if ($logger instanceof Monolog) {
                    $logger->close();
                }

                $log->forgetChannel($name);
            }
        });
    }
}
