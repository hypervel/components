<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PDO;

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

    public function testFlushStateClearsMacros()
    {
        try {
            DatabaseManager::macro('stateTest', fn () => 'state');

            $this->assertTrue(DatabaseManager::hasMacro('stateTest'));

            DatabaseManager::flushState();

            $this->assertFalse(DatabaseManager::hasMacro('stateTest'));
        } finally {
            DatabaseManager::flushState();
        }
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

    public function testIntegerBackedEnumConnectionNameIsNormalizedAcrossManagerLifecycle(): void
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], '0');

        $manager = $this->db->getDatabaseManager();
        $connection = $manager->connection(DatabaseManagerConnectionName::Zero);

        $this->assertSame('0', $connection->getName());

        $manager->disconnect(DatabaseManagerConnectionName::Zero);
        $this->assertNull($connection->getRawPdo());

        $reconnected = $manager->reconnect(DatabaseManagerConnectionName::Zero);
        $this->assertSame($connection, $reconnected);
        $this->assertNotNull($reconnected->getRawPdo());

        $manager->purge(DatabaseManagerConnectionName::Zero);

        $this->assertNotSame($connection, $manager->connection(DatabaseManagerConnectionName::Zero));
    }

    public function testEmptyConnectionNamesUseTheDefaultAcrossManagerLifecycle(): void
    {
        $manager = $this->db->getDatabaseManager();
        $connection = $manager->connection();

        $this->assertSame($connection, $manager->connection(''));

        $manager->disconnect('');
        $this->assertNull($connection->getRawPdo());

        $this->assertSame($connection, $manager->reconnect(''));

        $manager->purge('');

        $this->assertNotSame($connection, $manager->connection());
    }

    public function testUsingConnectionNormalizesIntegerBackedEnumAndRestoresTheDefault(): void
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], '0');

        $manager = $this->db->getDatabaseManager();

        $this->assertSame('0', $manager->usingConnection(
            DatabaseManagerConnectionName::Zero,
            fn (): ?string => $manager->connection()->getName()
        ));
        $this->assertSame('default', $manager->connection()->getName());
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

    public function testNonPooledReadConnectionCanBeResolvedFromSplitConfig(): void
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => [
                'database' => ':memory:',
            ],
        ], 'split');

        $connection = $this->db->getDatabaseManager()->connection('split::read');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('split', $connection->getName());
        $this->assertSame('read', $connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        $this->assertNotNull($connection->getPdo());
    }

    public function testNonPooledReadConnectionReconnectsUsingReadSuffix(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('DatabaseManagerTest-read-reconnect');
        $filesystem->ensureDirectoryExists($directory);

        $readPath = $directory . '/read.sqlite';
        $writePath = $directory . '/write.sqlite';
        $connection = null;

        try {
            $this->createSqliteUsersDatabase($readPath, 'Read Side');
            $this->createSqliteUsersDatabase($writePath, 'Write Side');

            $this->db->addConnection([
                'driver' => 'sqlite',
                'database' => $writePath,
                'read' => [
                    'database' => $readPath,
                ],
                'write' => [
                    'database' => $writePath,
                ],
            ], 'split-reconnect');

            $connection = $this->db->getDatabaseManager()->connection('split-reconnect::read');
            $this->assertSame('Read Side', $connection->selectOne('select name from users')->name);

            $connection->setPdo(null);
            $connection->reconnectIfMissingConnection();

            $this->assertSame('Read Side', $connection->selectOne('select name from users')->name);
        } finally {
            if ($connection instanceof Connection) {
                $connection->disconnect();
            }

            $filesystem->deleteDirectory($directory);
        }
    }

    public function testNonPooledWriteConnectionReconnectsUsingWriteSide(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('DatabaseManagerTest-write-reconnect');
        $filesystem->ensureDirectoryExists($directory);

        $readPath = $directory . '/read.sqlite';
        $writePath = $directory . '/write.sqlite';
        $connection = null;

        try {
            $this->createSqliteUsersDatabase($readPath, 'Read Side');
            $this->createSqliteUsersDatabase($writePath, 'Write Side');

            $this->db->addConnection([
                'driver' => 'sqlite',
                'database' => $writePath,
                'read' => [
                    'database' => $readPath,
                ],
                'write' => [
                    'database' => $writePath,
                ],
            ], 'split-write-reconnect');

            $connection = $this->db->getDatabaseManager()->connection('split-write-reconnect::write');
            $this->assertSame('Write Side', $connection->selectOne('select name from users')->name);

            $connection->setPdo(null);
            $connection->reconnectIfMissingConnection();

            $this->assertSame('Write Side', $connection->selectOne('select name from users')->name);
        } finally {
            if ($connection instanceof Connection) {
                $connection->disconnect();
            }

            $filesystem->deleteDirectory($directory);
        }
    }

    public function testNonPooledWriteConnectionForcesReadsThroughWritePdo(): void
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => [
                'database' => ':memory:',
            ],
            'write' => [
                'database' => ':memory:',
            ],
        ], 'split');

        $connection = $this->db->getDatabaseManager()->connection('split::write');
        $connection->statement('create table users (id integer primary key, name varchar)');
        $connection->insert('insert into users (name) values (?)', ['Taylor']);

        $this->assertSame('split', $connection->getName());
        $this->assertNull($connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        $this->assertSame('Taylor', $connection->selectOne('select name from users')->name);
    }

    public function testReadAndWriteSuffixesAreCompatibilityAliasesForUnsplitConnections(): void
    {
        $manager = $this->db->getDatabaseManager();

        $read = $manager->connection('default::read');
        $write = $manager->connection('default::write');

        $this->assertInstanceOf(Connection::class, $read);
        $this->assertInstanceOf(Connection::class, $write);
        $this->assertSame('default', $read->getName());
        $this->assertSame('default', $write->getName());
        $this->assertNull($read->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        $this->assertNull($write->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
    }

    public function testDirectConnectionSuffixIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Database connection suffix [::direct] is not supported. Configure a direct connection and use migrations_connection instead.'
        );

        $this->db->getDatabaseManager()->connection('default::direct');
    }

    public function testDisconnectOnlyDisconnectsRequestedNonPooledConnectionVariant(): void
    {
        $manager = $this->db->getDatabaseManager();

        $default = $manager->connection('default');
        $read = $manager->connection('default::read');
        $write = $manager->connection('default::write');

        $this->assertNotNull($default->getPdo());
        $this->assertNotNull($read->getPdo());
        $this->assertNotNull($write->getPdo());

        $manager->disconnect('default');

        $this->assertNull($default->getRawPdo());
        $this->assertNotNull($read->getRawPdo());
        $this->assertNotNull($write->getRawPdo());
    }

    public function testDisconnectCanTargetSuffixedNonPooledConnectionVariant(): void
    {
        $manager = $this->db->getDatabaseManager();

        $default = $manager->connection('default');
        $read = $manager->connection('default::read');
        $write = $manager->connection('default::write');

        $this->assertNotNull($default->getPdo());
        $this->assertNotNull($read->getPdo());
        $this->assertNotNull($write->getPdo());

        $manager->disconnect('default::read');

        $this->assertNotNull($default->getRawPdo());
        $this->assertNull($read->getRawPdo());
        $this->assertNotNull($write->getRawPdo());
    }

    public function testPurgeClearsNonPooledConnectionVariants(): void
    {
        $manager = $this->db->getDatabaseManager();

        $default = $manager->connection('default');
        $read = $manager->connection('default::read');
        $write = $manager->connection('default::write');

        $manager->purge('default');

        $this->assertNotSame($default, $manager->connection('default'));
        $this->assertNotSame($read, $manager->connection('default::read'));
        $this->assertNotSame($write, $manager->connection('default::write'));
    }

    protected function createSqliteUsersDatabase(string $path, string $name): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('create table users (id integer primary key, name varchar)');
        $statement = $pdo->prepare('insert into users (name) values (?)');
        $statement->execute([$name]);
    }
}

enum DatabaseManagerConnectionName: int
{
    case Zero = 0;
}
