<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

class SanctumServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::group(
            '/',
            __DIR__ . '/../routes/web.php',
            [
                'namespace' => 'Hypervel\Sanctum\Http\Controllers',
            ]
        );
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        // Services are registered via ConfigProvider
    }
}