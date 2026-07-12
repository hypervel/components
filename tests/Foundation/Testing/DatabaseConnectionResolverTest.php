<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Container\Container;
use Hypervel\Database\Connection;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
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

    public function testResetCachedConnectionsDiscardsConnectionsFromAnOldContainer(): void
    {
        $resolver = $this->app->make(DatabaseConnectionResolver::class);

        // Get a connection to cache it
        $connection = $resolver->connection();
        $this->assertNotNull($connection->getPdo());

        // Simulate a container change by creating a new container with a different object ID.
        // resetCachedConnections() detects this via spl_object_id and should disconnect
        // all cached connections before clearing them.
        $originalContainer = Container::getInstance();

        try {
            Container::setInstance(new Container);

            DatabaseConnectionResolver::resetCachedConnections();

            // The old connection should have been disconnected
            $this->assertNull($connection->getRawPdo());
        } finally {
            Container::setInstance($originalContainer);
        }
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

        DatabaseConnectionResolver::resetCachedConnections();

        $cachedConnection = $resolver->connection('testing_readwrite_suffix::write');

        $this->assertSame($connection, $cachedConnection);
        $this->assertSame('Taylor', $cachedConnection->selectOne('select name from users')->name);
    }

    public function testNamedFlushDiscardsTheOwningWrapperAndRestoresPoolCapacity(): void
    {
        $this->app->make('config')->set('database.connections.testing.pool.max_connections', 1);
        $resolver = $this->app->make(DatabaseConnectionResolver::class);
        $first = $resolver->connection();

        $resolver->flush($first->getName());

        $this->assertNull($first->getRawPdo());
        $this->assertNotSame($first, $second = $resolver->connection());
        $this->assertNotNull($second->getPdo());
    }

    public function testTerminalFlushDiscardsEveryCachedWrapper(): void
    {
        $resolver = $this->app->make(DatabaseConnectionResolver::class);
        $connection = $resolver->connection();

        DatabaseConnectionResolver::flushCachedConnections();

        $this->assertNull($connection->getRawPdo());
        $this->assertNotSame($connection, $resolver->connection());
    }

    public function testDiscardInvalidatesOnlyItsBareSharedSqliteConnection(): void
    {
        $pool = $this->app->make(PoolFactory::class)->getPool('testing');
        $pooled = $pool->get();
        $this->assertInstanceOf(PooledConnection::class, $pooled);
        $connection = $pooled->getConnection();
        $connection->statement('create table ownership_test (value varchar)');
        $connection->insert('insert into ownership_test (value) values (?)', ['preserved']);

        $pooled->discard();

        $this->assertNull($connection->getRawPdo());
        $replacement = $pool->get();
        $this->assertSame(
            'preserved',
            $replacement->getConnection()->selectOne('select value from ownership_test')->value,
        );
        $replacement->release();
    }

    public function testDiscardAndReconnectRollBackSharedSqliteTransactions(): void
    {
        $pool = $this->app->make(PoolFactory::class)->getPool('testing');
        $sharedPdo = $pool->getSharedInMemorySqlitePdo();
        $this->assertNotNull($sharedPdo);

        $discarded = $pool->get();
        $discarded->getConnection()->beginTransaction();
        $this->assertTrue($sharedPdo->inTransaction());
        $discarded->discard();
        $this->assertFalse($sharedPdo->inTransaction());

        $reconnected = $pool->get();
        $reconnected->getConnection()->beginTransaction();
        $this->assertTrue($sharedPdo->inTransaction());
        $this->assertTrue($reconnected->reconnect());
        $this->assertFalse($sharedPdo->inTransaction());
        $reconnected->discard();
    }
}
