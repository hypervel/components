<?php

declare(strict_types=1);

namespace Hypervel\Database;

/**
 * Interface for connection resolvers that maintain internal caches.
 *
 * Resolvers implementing this interface can have their caches cleared
 * when DatabaseManager::purge() is called, ensuring fresh connections
 * with updated configuration.
 */
interface FlushableConnectionResolver
{
    /**
     * Flush a cached connection.
     *
     * Called by purge() to clear any resolver-level caching for the
     * given connection name.
     */
    public function flush(string $name): void;
}
