<?php

declare(strict_types=1);

namespace Hypervel\Passkeys;

use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Passkeys\Console\PruneOrphanedPasskeys;
use Hypervel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Hypervel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
use Hypervel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Hypervel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Hypervel\Passkeys\Http\Responses\PasskeyConfirmationResponse;
use Hypervel\Passkeys\Http\Responses\PasskeyDeletedResponse;
use Hypervel\Passkeys\Http\Responses\PasskeyLoginResponse;
use Hypervel\Passkeys\Http\Responses\PasskeyRegistrationResponse;
use Hypervel\Routing\Router;
use Hypervel\Support\ServiceProvider;

class PasskeysServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/passkeys.php', 'passkeys');

        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
        $this->app->bind(PasskeyRegistrationResponseContract::class, PasskeyRegistrationResponse::class);
        $this->app->singleton(PasskeyDeletedResponseContract::class, PasskeyDeletedResponse::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }

        $this->registerRoutes();
        $this->registerRouteBindings();
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/passkeys.php' => config_path('passkeys.php'),
        ], 'passkeys-config');

        $this->publishesMigrations([
            Passkeys::migrationPath() => database_path('migrations'),
        ], 'passkeys-migrations');
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        if (Passkeys::shouldRegisterRoutes()) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
        }
    }

    /**
     * Register the package route bindings.
     */
    protected function registerRouteBindings(): void
    {
        $this->app->make(Router::class)->bind('passkey', function (string $value): Passkey {
            $model = Passkeys::passkeyModel();

            $passkey = $this->app->make($model)->resolveRouteBinding($value);

            if (! $passkey instanceof Passkey) {
                throw (new ModelNotFoundException)->setModel($model, [$value]);
            }

            return $passkey;
        });
    }

    /**
     * Register the console commands for the package.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PruneOrphanedPasskeys::class,
        ]);
    }
}
