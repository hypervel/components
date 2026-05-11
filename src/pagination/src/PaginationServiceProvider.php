<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Hypervel\Support\ServiceProvider;

class PaginationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'pagination');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/pagination'),
            ], 'hypervel-pagination');
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        PaginationState::resolveUsing($this->app);
    }
}
