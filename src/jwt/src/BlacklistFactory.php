<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hypervel\Contracts\Cache\Factory as CacheManager;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Storage\TaggedCache;
use Psr\Container\ContainerInterface;

class BlacklistFactory
{
    public function __invoke(ContainerInterface $container): BlacklistContract
    {
        $config = $container->get('config');

        $storageClass = $config->get('jwt.providers.storage');
        $storage = match ($storageClass) {
            TaggedCache::class => new TaggedCache($container->get(CacheManager::class)->driver()),
            default => $container->get($storageClass),
        };

        return new Blacklist(
            $storage,
            (int) $config->get('jwt.blacklist_grace_period', 0),
            (int) $config->get('jwt.blacklist_refresh_ttl', 20160)
        );
    }
}
