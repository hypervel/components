<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hypervel\Auth\AuthManager;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Storage\TaggedCache;
use Hypervel\Support\ServiceProvider;

class JWTServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/jwt.php', 'jwt');

        $this->app->singleton(BlacklistContract::class, function ($app) {
            $config = $app->make('config');

            $storageClass = $config->string('jwt.providers.storage');
            $storage = match ($storageClass) {
                TaggedCache::class => new TaggedCache($app['cache']->store()),
                default => $app->make($storageClass),
            };

            return new Blacklist(
                $storage,
                $config->integer('jwt.blacklist_grace_period', 0),
                $config->integer('jwt.blacklist_refresh_ttl', 20160)
            );
        });

        $this->app->singleton('jwt', fn ($app) => new JWTManager($app));
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->registerJwtGuard();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/jwt.php' => config_path('jwt.php'),
            ], 'jwt-config');
        }
    }

    /**
     * Register the JWT authentication guard.
     */
    protected function registerJwtGuard(): void
    {
        $this->callAfterResolving(AuthManager::class, function (AuthManager $authManager) {
            $authManager->extend('jwt', function ($app, $name, $config) use ($authManager) {
                /** @var null|int $ttl */
                $ttl = $app->make('config')->get('jwt.ttl', 120);

                return new JwtGuard(
                    name: $name,
                    provider: $authManager->createUserProvider($config['provider'] ?? null),
                    jwtManager: $app->make('jwt'),
                    app: $app,
                    ttl: $ttl,
                );
            });
        });
    }
}
