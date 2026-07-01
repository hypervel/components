<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Database\Connection;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Testbench\TestCase;

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

    public function testCachedWriteConnectionReappliesWriteReadRoutingAfterReset(): void
    {
        $this->app->make('config')->set('database.connections.testing_readwrite_suffix', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => [
                'database' => ':memory:',
            ],
            'write' => [
                'database' => ':memory:',
            ],
        ]);

        $resolver = $this->app->make(DatabaseConnectionResolver::class);
        $connection = $resolver->connection('testing_readwrite_suffix::write');

        $connection->statement('create table users (id integer primary key, name varchar)');
        $connection->insert('insert into users (name) values (?)', ['Taylor']);
        $this->assertSame('Taylor', $connection->selectOne('select name from users')->name);

        DatabaseConnectionResolver::flushCachedConnections();

        $cachedConnection = $resolver->connection('testing_readwrite_suffix::write');

        $this->assertSame($connection, $cachedConnection);
        $this->assertSame('Taylor', $cachedConnection->selectOne('select name from users')->name);
    }
}
