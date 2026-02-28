<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hypervel\Contracts\Cache\Factory as CacheManager;
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
        $this->app->singleton(BlacklistContract::class, function ($app) {
            $config = $app->make('config');

            $storageClass = $config->get('jwt.providers.storage');
            $storage = match ($storageClass) {
                TaggedCache::class => new TaggedCache($app->make(CacheManager::class)->store()),
                default => $app->make($storageClass),
            };

            return new Blacklist(
                $storage,
                (int) $config->get('jwt.blacklist_grace_period', 0),
                (int) $config->get('jwt.blacklist_refresh_ttl', 20160)
            );
        });

        $this->app->singleton('jwt', fn ($app) => new JWTManager($app));
    }
}
