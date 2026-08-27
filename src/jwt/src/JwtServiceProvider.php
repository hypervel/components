<?php

declare(strict_types=1);

namespace Hypervel\Jwt;

use Hypervel\Auth\AuthManager;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Jwt\Console\JwtGenerateCertsCommand;
use Hypervel\Jwt\Console\JwtSecretCommand;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Contracts\StorageContract;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\Http\Parser\InputSource;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Jwt\Storage\TaggedCache;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class JwtServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/jwt.php', 'jwt');

        // Hypervel intentionally keeps JWT as an array-based manager/guard package.
        // Upstream object/facade bindings hold mutable request state that does not
        // fit worker-lifetime singleton guards.
        $this->app->singleton('jwt', fn ($app) => new JwtManager(
            $app,
            $app->make(ClaimFactory::class),
        ));

        $this->app->singleton(Parser::class, function ($app) {
            $config = $app->make('config');
            $tokenKey = $config->string('jwt.token');

            $chain = array_map(
                fn (string $extractor) => match ($extractor) {
                    InputSource::class, Cookie::class => new $extractor($tokenKey),
                    default => $app->make($extractor),
                },
                $config->array('jwt.parser'),
            );

            // The parser chain is stateless; request instances are passed per parse so
            // coroutine requests cannot leak through a singleton parser.
            return new Parser($chain);
        });

        $this->app->singleton(BlacklistContract::class, function ($app) {
            $config = $app->make('config');

            $storageClass = $config->string('jwt.providers.storage', TaggedCache::class);
            $storage = match ($storageClass) {
                TaggedCache::class => new TaggedCache($this->cacheStoreForJwtBlacklist(
                    $app,
                    $config->boolean('jwt.blacklist_enabled')
                )),
                default => $app->make($storageClass),
            };

            /** @var null|int $refreshTtl */
            $refreshTtl = $config->get('jwt.refresh_ttl');

            return new Blacklist(
                storage: $storage,
                gracePeriod: $config->integer('jwt.blacklist_grace_period'),
                refreshTTL: $refreshTtl,
                leeway: $config->integer('jwt.leeway'),
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                JwtGenerateCertsCommand::class,
                JwtSecretCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->registerJwtGuard();

        // Sliding refresh middleware is intentionally not registered; refresh
        // belongs in an explicit endpoint via JwtGuard::refresh().
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
                $ttl = array_key_exists('ttl', $config)
                    ? $config['ttl']
                    : $app->make('config')->get('jwt.ttl');

                if (! is_int($ttl) && $ttl !== null) {
                    throw new InvalidArgumentException(
                        "JWT TTL for auth guard [{$name}] must be an integer or null."
                    );
                }

                $guard = new JwtGuard(
                    name: $name,
                    provider: $authManager->createUserProvider($config['provider']),
                    jwtManager: $app->make('jwt'),
                    claimFactory: $app->make(ClaimFactory::class),
                    parser: $app->make(Parser::class),
                    app: $app,
                    ttl: $ttl,
                );

                $guard->setDispatcher($app->make('events'));

                return $guard;
            });
        });
    }

    /**
     * Resolve the cache store for JWT blacklist storage.
     */
    protected function cacheStoreForJwtBlacklist(Container $app, bool $blacklistEnabled): CacheRepository
    {
        /** @var CacheRepository $repository */
        $repository = $app->make('cache')->store();

        if ($blacklistEnabled && ! $repository->supportsTags()) {
            throw new RuntimeException(
                'The JWT blacklist requires a taggable cache store (all-mode or any-mode). '
                . 'Use a taggable store or configure a custom ' . StorageContract::class
                . ' implementation in jwt.providers.storage.'
            );
        }

        return $repository;
    }
}
