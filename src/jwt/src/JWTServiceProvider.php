<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hypervel\Auth\AuthManager;
use Hypervel\JWT\Console\JwtGenerateCertsCommand;
use Hypervel\JWT\Console\JwtSecretCommand;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Http\Middleware\AuthenticateAndRenew;
use Hypervel\JWT\Http\Middleware\RefreshToken;
use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\Cookie;
use Hypervel\JWT\Http\Parser\InputSource;
use Hypervel\JWT\Http\Parser\Parser;
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

        // Hypervel intentionally keeps JWT as an array-based manager/guard package.
        // Upstream object/facade bindings hold mutable request state that does not
        // fit worker-lifetime singleton guards.
        $this->app->singleton('jwt', fn ($app) => new JWTManager(
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
                TaggedCache::class => new TaggedCache($app->make('cache')->store()),
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
        $this->registerMiddleware();

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
     * Register middleware aliases.
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app->make('router');

        $router->aliasMiddleware('jwt.refresh', RefreshToken::class);
        $router->aliasMiddleware('jwt.renew', AuthenticateAndRenew::class);
    }
}
