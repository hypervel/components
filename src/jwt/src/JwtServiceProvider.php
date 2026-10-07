<?php

declare(strict_types=1);

namespace Hypervel\Jwt;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Container\Container;
use Hypervel\Jwt\Console\JwtGenerateCertsCommand;
use Hypervel\Jwt\Console\JwtSecretCommand;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Contracts\TokenExtractor;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\Http\Parser\InputSource;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Jwt\Storage\CacheStorage;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;

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

        $this->app->singleton(Parser::class, function (Container $app): Parser {
            $config = $app->make('config');

            $chain = array_map(
                fn (string $extractor): TokenExtractor => match ($extractor) {
                    InputSource::class => new InputSource($config->string('jwt.token')),
                    Cookie::class => new Cookie($config->string('jwt.cookie_key_name')),
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

            $storageClass = $config->string('jwt.providers.storage', CacheStorage::class);
            $storage = match ($storageClass) {
                CacheStorage::class => new CacheStorage(
                    $app->make('cache')->store($config->get('jwt.blacklist_store'))
                ),
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
        $this->callAfterResolving(AuthManager::class, function (AuthManager $authManager): void {
            $authManager->extend('jwt', function (Container $app, string $name, array $config) use ($authManager): JwtGuard {
                $repository = $app->make('config');

                $ttl = array_key_exists('ttl', $config)
                    ? $config['ttl']
                    : $repository->get('jwt.ttl');

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
                    rehashOnLogin: $repository->boolean('hashing.rehash_on_login'),
                    timeboxDuration: $repository->integer('auth.timebox_duration'),
                    ttl: $ttl,
                );

                $guard->setDispatcher($app->make('events'));

                return $guard;
            });
        });
    }
}
