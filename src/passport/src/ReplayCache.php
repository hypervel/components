<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Config\Repository;
use LogicException;

class ReplayCache
{
    /**
     * Create the shared assertion and proof replay store.
     */
    public function __construct(
        protected Factory $cache,
        protected Repository $config,
    ) {
    }

    /**
     * Consume a validated assertion or proof until its acceptance window ends.
     */
    public function consume(string $identifier, int $expiresAt): bool
    {
        $seconds = $expiresAt - time();

        if ($seconds <= 0) {
            return false;
        }

        $name = $this->config->get('passport.replay.store');
        $repository = $this->cache->store($name === '' ? null : $name);
        $store = $repository->getStore();

        // Switching stores or evicting local state would allow the same proof to be accepted again.
        if (! $store instanceof RedisStore && ! $store instanceof DatabaseStore) {
            throw new LogicException('Passport replay protection requires a direct Redis or database cache store.');
        }

        if ($store instanceof DatabaseStore && $store->getConnection()->transactionLevel() > 0) {
            throw new LogicException('Passport replay protection cannot use a cache connection with an active transaction.');
        }

        return $repository->add('passport:replay:' . hash('sha256', $identifier), true, $seconds);
    }
}
