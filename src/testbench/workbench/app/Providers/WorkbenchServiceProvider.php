<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->loadMigrationsFrom(realpath(__DIR__ . '/../../database/migrations'));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Route::macro('text', function (string $url, string $content) {
            return $this->get($url, fn () => response($content)->header('Content-Type', 'text/plain')); /* @phpstan-ignore method.notFound */
        });
    }
}
