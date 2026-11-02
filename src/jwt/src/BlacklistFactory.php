<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Cache\Contracts\Factory as CacheManager;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Storage\TaggedCache;
use Psr\Container\ContainerInterface;

class BlacklistFactory
{
    public function __invoke(ContainerInterface $container): BlacklistContract
    {
        $config = $container->get(ConfigInterface::class);

        $storageClass = $config->get('jwt.providers.storage');
        $storage = match ($storageClass) {
            TaggedCache::class => new TaggedCache($container->get(CacheManager::class)->driver()),
            default => $container->get($storageClass),
        };

        // Blacklisted tokens must stay blacklisted for as long as they can be refreshed.
        $refreshTTL = $config->get('jwt.refresh_ttl', 20160);
        if (! is_null($refreshTTL)) {
            $refreshTTL = max((int) $refreshTTL, (int) $config->get('jwt.blacklist_refresh_ttl', 20160));
        }

        return new Blacklist(
            $storage,
            (int) $config->get('jwt.blacklist_grace_period', 0),
            $refreshTTL
        );
    }
}
