<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\Providers;

use Hypervel\Console\Application;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Integration\Foundation\Fixtures\Console\ThrowExceptionCommand;
use Hypervel\Tests\Integration\Foundation\Fixtures\Logs\ThrowExceptionLogHandler;

class ThrowUncaughtExceptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];

        $config->set('logging.default', 'throw_exception');

        $config->set('logging.channels.throw_exception', [
            'driver' => 'monolog',
            'handler' => ThrowExceptionLogHandler::class,
        ]);
    }

    public function boot(): void
    {
        Application::starting(function ($artisan) {
            $artisan->add(new ThrowExceptionCommand);
        });
    }
}
