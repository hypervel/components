<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hypervel\Contracts\Cache\Factory as CacheManager;
use Hypervel\Contracts\Container\Container;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Storage\TaggedCache;

class BlacklistFactory
{
    public function __invoke(Container $container): BlacklistContract
    {
        $config = $container->make('config');

        $storageClass = $config->get('jwt.providers.storage');
        $storage = match ($storageClass) {
            TaggedCache::class => new TaggedCache($container->make(CacheManager::class)->store()),
            default => $container->make($storageClass),
        };

        return new Blacklist(
            $storage,
            (int) $config->get('jwt.blacklist_grace_period', 0),
            (int) $config->get('jwt.blacklist_refresh_ttl', 20160)
        );
    }
}
