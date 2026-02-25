<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler;

use Hypervel\ExceptionHandler\Formatter\DefaultFormatter;
use Hypervel\ExceptionHandler\Formatter\FormatterInterface;
use Hypervel\ExceptionHandler\Listener\ExceptionHandlerListener;
use Hypervel\Framework\Events\BootApplication;
use Hypervel\Support\ServiceProvider;

class ExceptionHandlerServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(FormatterInterface::class, DefaultFormatter::class);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->app->make(ExceptionHandlerListener::class)->process(new BootApplication());
    }
}
