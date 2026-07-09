<?php

declare(strict_types=1);

namespace Hypervel\Jwt;

use Hypervel\Auth\AuthManager;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Jwt\Console\JwtGenerateCertsCommand;
use Hypervel\Jwt\Console\JwtSecretCommand;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\Http\Parser\InputSource;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Jwt\Storage\TaggedCache;
use Hypervel\Support\ServiceProvider;
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
            $tokenKey = $config->string('jwt.token', 'token');

            $chain = array_map(
                fn (string $extractor) => match ($extractor) {
                    InputSource::class, Cookie::class => new $extractor($tokenKey),
                    default => $app->make($extractor),
                },
                $config->array('jwt.parser', [AuthHeaders::class]),
            );

            // The parser chain is stateless; request instances are passed per parse so
            // coroutine requests cannot leak through a singleton parser.
            return new Parser($chain);
        });

        $this->app->singleton(BlacklistContract::class, function ($app) {
            $config = $app->make('config');

            $storageClass = $config->string('jwt.providers.storage');
            $storage = match ($storageClass) {
                TaggedCache::class => new TaggedCache($this->cacheStoreForJwtBlacklist(
                    $app,
                    $config->boolean('jwt.blacklist_enabled', false)
                )),
                default => $app->make($storageClass),
            };

            return new Blacklist(
                $storage,
                $config->integer('jwt.blacklist_grace_period', 0),
                $config->integer('jwt.blacklist_refresh_ttl', 20160)
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
                /** @var null|int $ttl */
                $ttl = array_key_exists('ttl', $config)
                    ? $config['ttl']
                    : $app->make('config')->get('jwt.ttl', 120);

                $guard = new JwtGuard(
                    name: $name,
                    provider: $authManager->createUserProvider($config['provider'] ?? null),
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
                . 'Use a taggable store or set a custom jwt.providers.storage.'
            );
        }

        return $repository;
    }
}
