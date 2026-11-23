<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class UnrecognizedFlowProvider extends ServiceProvider
{
    /**
     * Use the container and a local repository in ways that are not recognized writes.
     */
    public function register(): void
    {
        $this->configureUsing(static function (): void {
            app(LoggerInterface::class)->info('configured');
        });

        if ($this->app->runningInConsole()) {
            $this->app->make('events')->dispatch('configured');
        }

        // The tracker records this write when it runs; the static pass does not follow locals.
        $config = $this->app->make('config');
        $config->set($this->app->environment(), true);
    }
}
