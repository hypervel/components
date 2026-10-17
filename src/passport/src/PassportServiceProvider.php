<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Passport\Contracts\ApprovedDeviceAuthorizationResponse as ApprovedDeviceAuthorizationResponseContract;
use Hypervel\Passport\Contracts\DeniedDeviceAuthorizationResponse as DeniedDeviceAuthorizationResponseContract;
use Hypervel\Passport\Contracts\ReauthenticationResponse as ReauthenticationResponseContract;
use Hypervel\Passport\Enums\KeyStore;
use Hypervel\Passport\Guards\TokenGuard;
use Hypervel\Passport\Http\Responses\ApprovedDeviceAuthorizationResponse;
use Hypervel\Passport\Http\Responses\DeniedDeviceAuthorizationResponse;
use Hypervel\Passport\Http\Responses\ReauthenticationResponse;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\Facades\Cookie;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;

class PassportServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPublishing();
        $this->registerCommands();

        $this->deleteCookieOnLogout();
        $this->confirmPendingAuthorizationsOnLogin();
        $this->configureCache();
        $this->configureRateLimiter();
        $this->scheduleKeyRotation();

        if ($this->app->runningInConsole()) {
            $this->app->booted(fn () => $this->validateConfiguration());
        } else {
            Event::listen(AfterWorkerStart::class, function (): void {
                $this->validateConfiguration();
            });
        }
    }

    /**
     * Register the Passport routes.
     */
    protected function registerRoutes(): void
    {
        if (Passport::$registersRoutes && ! $this->app->routesAreCached()) {
            Route::group([
                'as' => 'passport.',
                'prefix' => config()->string('passport.path'),
                'namespace' => 'Hypervel\Passport\Http\Controllers',
                'middleware' => config()->array('passport.middleware'),
            ], function (): void {
                $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
            });
        }
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'passport-migrations');

            $this->publishes([
                __DIR__ . '/../config/passport.php' => config_path('passport.php'),
            ], 'passport-config');
        }
    }

    /**
     * Register the Passport Artisan commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\ClientCommand::class,
                // The pre-13 passport:hash migration command is omitted.
                Console\KeysCommand::class,
                Console\KeysImportCommand::class,
                Console\KeysListCommand::class,
                Console\KeysRevokeCommand::class,
                Console\KeysRotateCommand::class,
                Console\PurgeCommand::class,
            ]);
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/passport.php', 'passport');

        $this->registerResponseBindings();
        $this->registerAuthorizationServer();
        $this->registerResourceServer();
        $this->registerGuard();
    }

    /**
     * Register the response bindings.
     */
    protected function registerResponseBindings(): void
    {
        $this->app->bind(ReauthenticationResponseContract::class, ReauthenticationResponse::class);
        $this->app->singleton(ApprovedDeviceAuthorizationResponseContract::class, ApprovedDeviceAuthorizationResponse::class);
        $this->app->singleton(DeniedDeviceAuthorizationResponseContract::class, DeniedDeviceAuthorizationResponse::class);
    }

    /**
     * Register the authorization server.
     */
    protected function registerAuthorizationServer(): void
    {
        $this->app->bind(
            AuthorizationServer::class,
            fn () => $this->app->make(ServerFactory::class)->authorizationServer()
        );
    }

    // Server and grant construction lives on ServerFactory; implicit and password grants are omitted.

    /**
     * Register the resource server.
     */
    protected function registerResourceServer(): void
    {
        $this->app->bind(
            ResourceServer::class,
            fn () => $this->app->make(ServerFactory::class)->resourceServer()
        );
    }

    /**
     * Register the token guard.
     */
    protected function registerGuard(): void
    {
        $this->callAfterResolving(AuthManager::class, function (AuthManager $auth): void {
            // Keep the protected factory in the provider's scope when AuthManager binds the creator.
            $requestGuardCreator = fn (string $name, array $config): TokenGuard => $this->makeGuard($name, $config);

            $auth->extend('passport', static fn (Container $app, string $name, array $config): TokenGuard => $requestGuardCreator($name, $config));
        });
    }

    /**
     * Make an instance of the token guard.
     *
     * @param array<string, mixed> $config
     */
    protected function makeGuard(string $name, array $config): TokenGuard
    {
        return new TokenGuard(
            $name,
            $this->app->make(ServerFactory::class),
            new PassportUserProvider($this->app->make(AuthManager::class)->createUserProvider($config['provider']), $config['provider']),
            $this->app->make(ClientRepository::class),
            $this->app->make('encrypter'),
            $this->app
        );
    }

    /**
     * Register the cookie deletion event handler.
     */
    protected function deleteCookieOnLogout(): void
    {
        Event::listen(Logout::class, function (): void {
            if (Request::hasCookie(Passport::cookie())) {
                Cookie::queue($this->app->make(ApiTokenCookieFactory::class)->forget());
            }
        });
    }

    /**
     * Recognize fresh guest authentication without treating cookie recall as a login challenge.
     */
    protected function confirmPendingAuthorizationsOnLogin(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            $request = $this->app->make(HttpRequest::class);

            if (! $request->hasSession()) {
                return;
            }

            $auth = $this->app->make(AuthManager::class);
            $guard = $auth->guard($event->guard);

            // Recall can occur on an earlier request; only the Login event distinguishes it reliably.
            if ($guard instanceof StatefulGuard && ! $guard->viaRemember()) {
                $this->app->make(PendingAuthorizationRepository::class)->confirmLogin(
                    $request,
                    $event->guard,
                    (string) $event->user->getAuthIdentifier(),
                    $auth->getUserProviderName($event->guard),
                );
            }
        });
    }

    /**
     * Allow cached Passport models to be restored by the cache store.
     */
    protected function configureCache(): void
    {
        $config = $this->app->make(Repository::class);

        $this->app->make(CacheManager::class)->allowSerializableClassesUsing(
            static fn (): array => $config->boolean('passport.cache.enabled')
                ? [Passport::clientModel(), Passport::tokenModel()]
                : []
        );
    }

    /**
     * Register the default token endpoint rate limiter unless the application supplied one.
     */
    protected function configureRateLimiter(): void
    {
        $limiter = $this->app->make(RateLimiter::class);

        $this->app->booted(static function () use ($limiter): void {
            if ($limiter->limiter('passport') === null) {
                $limiter->for('passport', static fn (HttpRequest $request): Limit => Limit::perMinute(60)
                    ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
            }
        });
    }

    /**
     * Schedule automatic rotation for database-backed signing keys.
     */
    protected function scheduleKeyRotation(): void
    {
        $config = $this->app->make(Repository::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($config): void {
            if (KeyStore::from($config->string('passport.key_store')) === KeyStore::Database
                && $config->boolean('passport.key_rotation.enabled')) {
                $schedule->command('passport:keys:rotate', ['--due'])
                    ->daily()->onOneServer()->withoutOverlapping();
            }
        });
    }

    /**
     * Validate the cache, server settings and credential connections during process startup.
     */
    protected function validateConfiguration(): void
    {
        $config = $this->app->make(Repository::class);

        if ($config->integer('passport.server_cache_size') <= 0) {
            throw new InvalidArgumentException('Passport server cache size must be a positive integer.');
        }

        if ($config->integer('passport.authorization.lifetime') <= 0 || $config->integer('passport.authorization.max_pending') <= 0) {
            throw new InvalidArgumentException('Passport authorization lifetime and pending request limit must be positive integers.');
        }

        $models = [
            Passport::token(), Passport::consent(), Passport::authCode(), Passport::deviceCode(),
            Passport::refreshToken(), Passport::refreshTokenFamily(),
        ];

        if (KeyStore::from($config->string('passport.key_store')) === KeyStore::Database) {
            $models[] = Passport::signingKey();
            $refreshInterval = $config->integer('passport.key_refresh_interval');

            if ($refreshInterval <= 0 || $config->integer('passport.key_activation_delay') <= $refreshInterval) {
                throw new InvalidArgumentException('Passport key activation delay must exceed a positive key refresh interval.');
            }

            if ($config->integer('passport.key_rotation.days') <= 0) {
                throw new InvalidArgumentException('Passport key rotation period must be a positive number of days.');
            }
        }

        $defaultConnection = $config->string('database.default');
        $connection = $models[0]->getConnectionName() ?? $defaultConnection;

        foreach ($models as $model) {
            if (($model->getConnectionName() ?? $defaultConnection) !== $connection) {
                throw new InvalidArgumentException('Passport credential and database signing-key models must share one database connection.');
            }
        }

        if (! $config->boolean('passport.cache.enabled')) {
            return;
        }

        if ($config->integer('passport.cache.ttl') <= 0) {
            throw new InvalidArgumentException('Passport cache TTL must be a positive integer.');
        }

        $store = $config->get('passport.cache.store');

        if (! is_string($store) && $store !== null) {
            throw new InvalidArgumentException('Passport cache store must be a string or null.');
        }

        $this->app->make(ModelCacheStoreValidator::class)->validate(
            $this->app->make(CacheManager::class)->store($store === '' ? null : $store),
            'Passport token and client cache',
        );
    }
}
