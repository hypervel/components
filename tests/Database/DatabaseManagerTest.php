<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Connection;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use PDO;

/**
 * @internal
 * @coversNothing
 */
class DatabaseManagerTest extends TestCase
{
    protected DB $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new DB;

        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function testDisconnectDisconnectsNonPooledConnection()
    {
        $manager = $this->db->getDatabaseManager();

        // Resolve a connection (populates $connections array via SimpleConnectionResolver)
        $connection = $manager->connection();
        $this->assertInstanceOf(Connection::class, $connection);

        // Verify the PDO is connected
        $this->assertNotNull($connection->getRawPdo());

        // Disconnect via the manager
        $manager->disconnect();

        // PDO should be nulled
        $this->assertNull($connection->getRawPdo());
    }

    public function testDisconnectWithNamedNonPooledConnection()
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'secondary');

        $manager = $this->db->getDatabaseManager();

        // Resolve both connections
        $default = $manager->connection('default');
        $secondary = $manager->connection('secondary');
        $this->assertNotNull($default->getRawPdo());
        $this->assertNotNull($secondary->getRawPdo());

        // Disconnect only secondary
        $manager->disconnect('secondary');

        // Secondary should be disconnected, default should remain
        $this->assertNull($secondary->getRawPdo());
        $this->assertNotNull($default->getRawPdo());
    }

    public function testDisconnectWithNoExistingConnectionDoesNotError()
    {
        $manager = $this->db->getDatabaseManager();

        // Should not throw — no connection has been resolved yet
        $manager->disconnect();

        $this->assertTrue(true);
    }

    public function testReconnectAfterDisconnectOnNonPooledConnection()
    {
        $manager = $this->db->getDatabaseManager();

        // Resolve, disconnect, then reconnect
        $connection = $manager->connection();
        $this->assertNotNull($connection->getRawPdo());

        $manager->disconnect();
        $this->assertNull($connection->getRawPdo());

        $reconnected = $manager->reconnect();
        $this->assertNotNull($reconnected->getRawPdo());
    }

    public function testExtendWorksEndToEndThroughNonPooledPath()
    {
        $custom = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
        $manager = $this->db->getDatabaseManager();

        $manager->extend('sqlite', function () use ($custom) {
            return $custom;
        });

        // Add a new connection to avoid the already-cached 'default'
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'extended');

        $result = $manager->connection('extended');

        $this->assertSame($custom, $result);
    }

    public function testForgetExtensionWorksEndToEndThroughNonPooledPath()
    {
        $custom = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
        $manager = $this->db->getDatabaseManager();

        $manager->extend('sqlite', function () use ($custom) {
            return $custom;
        });

        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'forget-test');

        // Extension active — should return custom connection
        $this->assertSame($custom, $manager->connection('forget-test'));

        // Purge the cached connection so next call resolves fresh
        $manager->purge('forget-test');

        // Forget the extension
        $manager->forgetExtension('sqlite');

        // Normal creation resumes
        $result = $manager->connection('forget-test');
        $this->assertNotSame($custom, $result);
        $this->assertInstanceOf(Connection::class, $result);
    }
}
