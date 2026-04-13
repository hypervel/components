<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Database\Connection;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseConnectionResolverTest extends TestCase
{
    public function testFlushDisconnectsCachedConnection()
    {
        $resolver = $this->app->make(DatabaseConnectionResolver::class);

        // Get a connection to cache it
        $connection = $resolver->connection();

        // Verify PDO is active
        $this->assertNotNull($connection->getPdo());

        // Flush should disconnect before removing from cache
        $resolver->flush($connection->getName());

        // The connection's PDO should be nulled (disconnected)
        $this->assertNull($connection->getRawPdo());
    }

    public function testFlushCachedConnectionsDisconnectsOnContainerChange()
    {
        $resolver = $this->app->make(DatabaseConnectionResolver::class);

        // Get a connection to cache it
        $connection = $resolver->connection();
        $this->assertNotNull($connection->getPdo());

        // Simulate a container change by creating a new container with a different object ID.
        // flushCachedConnections() detects this via spl_object_id and should disconnect
        // all cached connections before clearing them.
        $newContainer = new \Hypervel\Container\Container;
        \Hypervel\Container\Container::setInstance($newContainer);

        DatabaseConnectionResolver::flushCachedConnections();

        // The old connection should have been disconnected
        $this->assertNull($connection->getRawPdo());

        // Restore original container
        \Hypervel\Container\Container::setInstance($this->app);
    }
}
