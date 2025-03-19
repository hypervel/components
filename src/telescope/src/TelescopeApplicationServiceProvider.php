<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Support\Facades\Gate;
use Hypervel\Support\ServiceProvider;

class TelescopeApplicationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->authorization();
    }

    /**
     * Configure the Telescope authorization services.
     */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(function (RequestContract $request) {
            return $this->app->environment('local')
                || Gate::check('viewTelescope', [$request->user()]);
        });
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return in_array($user->email, [
            ]);
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
    }
}
