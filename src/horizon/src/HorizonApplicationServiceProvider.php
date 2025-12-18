<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Support\Facades\Gate;
use Hypervel\Support\ServiceProvider;
use Psr\Http\Message\ServerRequestInterface;

class HorizonApplicationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->authorization();
    }

    /**
     * Configure the Horizon authorization services.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function (ServerRequestInterface $request) {
            return Gate::check('viewHorizon', $request);
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($request) {
            return app()->environment('local');
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
    }
}
