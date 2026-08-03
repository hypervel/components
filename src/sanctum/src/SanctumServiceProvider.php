<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Auth\AuthManager;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Http\Request;
use Hypervel\Sanctum\Console\Commands\PruneExpired;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;

class SanctumServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sanctum.php',
            'sanctum'
        );
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $cache = $this->app->make(CacheManager::class);
        $config = $this->app->make(ConfigRepository::class);

        $cache->allowSerializableClassesUsing(function () use ($config): array {
            if (! $config->boolean('sanctum.cache.enabled')) {
                return [];
            }

            $models = [Sanctum::personalAccessTokenModel()];

            foreach ($config->array('auth.guards') as $guard) {
                if (! is_array($guard) || ($guard['driver'] ?? null) !== 'sanctum') {
                    continue;
                }

                $providerName = $guard['provider'] ?? null;

                if (! is_string($providerName)) {
                    continue;
                }

                $provider = $config->get("auth.providers.{$providerName}");

                if (! is_array($provider) || ($provider['driver'] ?? null) !== 'eloquent') {
                    continue;
                }

                $model = $provider['model'] ?? null;

                if (! is_string($model)
                    || ! is_a($model, Model::class, true)
                    || ! is_a($model, Authenticatable::class, true)) {
                    throw new InvalidArgumentException(
                        "Authentication provider [{$providerName}] model must be an Eloquent authenticatable class.",
                    );
                }

                $models[] = $model;
            }

            return [
                ...$models,
                EloquentCollection::class,
                Pivot::class,
                MorphPivot::class,
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->app->booted(
                fn () => $this->validateCacheStore($cache, $config),
            );
        } else {
            // Worker configuration is reloaded during BeforeWorkerStart.
            $events = $this->app->make('events');
            $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
                $this->validateCacheStore(
                    $this->app->make(CacheManager::class),
                    $this->app->make(ConfigRepository::class),
                );
            });
        }

        $this->registerSanctumGuard();
        $this->configureSessionCookies();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }

        $this->registerRoutes();
    }

    /**
     * Validate the configured token cache store.
     */
    private function validateCacheStore(CacheManager $cache, ConfigRepository $config): void
    {
        if (! $config->boolean('sanctum.cache.enabled')) {
            return;
        }

        $store = $config->get('sanctum.cache.store');

        if (! is_string($store) && $store !== null) {
            throw new InvalidArgumentException('Sanctum cache store must be a string or null.');
        }

        $this->app->make(ModelCacheStoreValidator::class)->validate(
            $cache->store($store === '' ? null : $store),
            'Sanctum token cache',
        );
    }

    /**
     * Register the Sanctum authentication guard.
     */
    protected function registerSanctumGuard(): void
    {
        $this->callAfterResolving(AuthManager::class, function (AuthManager $authManager) {
            $authManager->extend('sanctum', function ($app, $name, $config) use ($authManager) {
                $sessionGuards = $config['session_guards'] ?? null;
                $isSessionGuardName = static fn (mixed $guard): bool => is_string($guard) && $guard !== '';

                if (! is_array($sessionGuards) || array_filter($sessionGuards, $isSessionGuardName) !== $sessionGuards) {
                    throw new InvalidArgumentException(
                        "Auth guard [{$name}] uses the sanctum driver but does not declare a valid session guards list. "
                        . "Set auth.guards.{$name}.session_guards to an array of session guard names, or [] to disable stateful session authentication."
                    );
                }

                return new SanctumGuard(
                    name: $name,
                    provider: $authManager->createUserProvider($config['provider'] ?? null),
                    app: $app,
                    sessionGuards: $sessionGuards,
                    events: $app->bound('events') ? $app->make('events') : null,
                    expiration: $app->make('config')->get('sanctum.expiration'),
                    trackLastUsedAt: $app->make('config')->boolean('sanctum.last_used_at'),
                );
            });
        });
    }

    /**
     * Configure session cookies for stateful frontend requests.
     */
    protected function configureSessionCookies(): void
    {
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            if (! $request->attributes->get('sanctum')) {
                return $cookie;
            }

            return array_replace($cookie, [
                'http_only' => true,
                'same_site' => 'lax',
            ]);
        });
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(config('sanctum.middleware', 'web'))
            ->group(__DIR__ . '/../routes/web.php');
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/sanctum.php' => config_path('sanctum.php'),
        ], 'sanctum-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'sanctum-migrations');
    }

    /**
     * Register the console commands for the package.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PruneExpired::class,
        ]);
    }
}
