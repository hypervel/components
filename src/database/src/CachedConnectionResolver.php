<?php

declare(strict_types=1);

namespace Hypervel\Database;

/**
 * Interface for connection resolvers that maintain internal caches.
 */
interface CachedConnectionResolver
{
    /**
     * Get an already resolved connection from the cache.
     */
    public function getResolvedConnection(string $name): ?ConnectionInterface;

    /**
     * Flush a cached connection.
     */
    public function flush(string $name): void;
}
