<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\Providers;

use Hypervel\Console\Application;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Integration\Foundation\Fixtures\Console\ThrowExceptionCommand;

class ThrowExceptionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Application::starting(function ($artisan) {
            $artisan->add(new ThrowExceptionCommand);
        });
    }
}
