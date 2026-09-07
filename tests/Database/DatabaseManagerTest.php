<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Database\SQLiteDatabaseDoesNotExistException;
use Hypervel\Events\Dispatcher;
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

    // REMOVED: Capsule's setter writes unused configuration and cannot safely
    // define a connection-wide row shape. Use Query\Builder::fetchUsing() per query.

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

    public function testPassiveObserverDoesNotCauseConnectionEstablishedEventToDispatch(): void
    {
        $events = new Dispatcher;
        $this->db->setEventDispatcher($events);
        $establishedConnections = [];
        $events->observe(
            ConnectionEstablished::class,
            static function (ConnectionEstablished $event) use (&$establishedConnections): void {
                $establishedConnections[] = $event->connection;
            }
        );

        $connection = $this->db->getDatabaseManager()->connection();

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame([], $establishedConnections);
    }

    public function testNonPooledReconnectRefreshesInPlaceAndDispatchesOneEventAfterReplacement(): void
    {
        $events = new Dispatcher;
        $this->db->setEventDispatcher($events);
        $establishedConnections = [];
        $events->listen(
            ConnectionEstablished::class,
            static function (ConnectionEstablished $event) use (&$establishedConnections): void {
                $establishedConnections[] = $event->connection;
            }
        );
        $manager = $this->db->getDatabaseManager();
        $connection = $manager->connection();
        $oldPdo = $connection->getPdo();
        $connection->enableQueryLog();
        $connection->select('select 1');

        $reconnected = $manager->reconnect();

        $this->assertSame($connection, $reconnected);
        $this->assertNotSame($oldPdo, $reconnected->getPdo());
        $this->assertCount(1, $reconnected->getQueryLog());
        $this->assertSame([$connection, $connection], $establishedConnections);
    }

    public function testNonPooledReconnectEagerlyAdoptsCompleteSplitResourceGeneration(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('DatabaseManagerTest-generation-refresh');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $oldReadPath = $directory . '/old-read.sqlite';
        $oldWritePath = $directory . '/old-write.sqlite';
        $newReadPath = $directory . '/new-read.sqlite';
        $newWritePath = $directory . '/new-write.sqlite';
        $connection = null;

        try {
            $this->createSqliteUsersDatabase($oldReadPath, 'Old Read');
            $this->createSqliteUsersDatabase($oldWritePath, 'Old Write');
            $this->createSqliteUsersDatabase($newReadPath, 'New Read');
            $this->createSqliteUsersDatabase($newWritePath, 'New Write');
            $this->db->addConnection([
                'driver' => 'sqlite',
                'database' => $oldWritePath,
                'read' => ['database' => $oldReadPath],
                'write' => ['database' => $oldWritePath],
            ], 'generation-refresh');

            $events = new Dispatcher;
            $this->db->setEventDispatcher($events);
            $establishedConnections = [];
            $events->listen(
                ConnectionEstablished::class,
                static function (ConnectionEstablished $event) use (&$establishedConnections): void {
                    $establishedConnections[] = $event->connection;
                }
            );

            $manager = $this->db->getDatabaseManager();
            $connection = $manager->connection('generation-refresh');
            $oldWritePdo = $connection->getPdo();
            $oldReadPdo = $connection->getReadPdo();
            $establishedConnections = [];

            $this->db->addConnection([
                'driver' => 'sqlite',
                'database' => $newWritePath,
                'read' => ['database' => $newReadPath],
                'write' => ['database' => $newWritePath],
            ], 'generation-refresh');

            $reconnected = $manager->reconnect('generation-refresh');

            $this->assertSame($connection, $reconnected);
            $this->assertInstanceOf(PDO::class, $connection->getRawPdo());
            $this->assertInstanceOf(PDO::class, $connection->getRawReadPdo());
            $this->assertNotSame($oldWritePdo, $connection->getRawPdo());
            $this->assertNotSame($oldReadPdo, $connection->getRawReadPdo());
            $this->assertSame($newWritePath, $connection->getDatabaseName());
            $this->assertSame($newWritePath, $connection->getConfig('database'));
            $this->assertSame('New Read', $connection->selectOne('select name from users')->name);
            $this->assertSame('New Write', $connection->selectFromWriteConnection('select name from users')[0]->name);
            $this->assertSame([$connection], $establishedConnections);
        } finally {
            $connection?->disconnect();
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testFailedNonPooledReconnectPreservesCompleteSplitResourceGeneration(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('DatabaseManagerTest-generation-refresh-failure');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $oldReadPath = $directory . '/old-read.sqlite';
        $oldWritePath = $directory . '/old-write.sqlite';
        $newWritePath = $directory . '/new-write.sqlite';
        $missingReadPath = $directory . '/missing-read.sqlite';
        $connection = null;

        try {
            $this->createSqliteUsersDatabase($oldReadPath, 'Old Read');
            $this->createSqliteUsersDatabase($oldWritePath, 'Old Write');
            $this->createSqliteUsersDatabase($newWritePath, 'New Write');
            $this->db->addConnection([
                'driver' => 'sqlite',
                'database' => $oldWritePath,
                'read' => ['database' => $oldReadPath],
                'write' => ['database' => $oldWritePath],
            ], 'generation-refresh-failure');

            $events = new Dispatcher;
            $this->db->setEventDispatcher($events);
            $establishedConnections = [];
            $events->listen(
                ConnectionEstablished::class,
                static function (ConnectionEstablished $event) use (&$establishedConnections): void {
                    $establishedConnections[] = $event->connection;
                }
            );

            $manager = $this->db->getDatabaseManager();
            $connection = $manager->connection('generation-refresh-failure');
            $oldWritePdo = $connection->getPdo();
            $oldReadPdo = $connection->getReadPdo();
            $establishedConnections = [];

            $this->db->addConnection([
                'driver' => 'sqlite',
                'database' => $newWritePath,
                'read' => ['database' => $missingReadPath],
                'write' => ['database' => $newWritePath],
            ], 'generation-refresh-failure');

            $exception = null;

            try {
                $manager->reconnect('generation-refresh-failure');
            } catch (SQLiteDatabaseDoesNotExistException $sqliteException) {
                $exception = $sqliteException;
            }

            $this->assertNotNull($exception);
            $this->assertSame($missingReadPath, $exception->path);
            $this->assertSame($oldWritePdo, $connection->getRawPdo());
            $this->assertSame($oldReadPdo, $connection->getRawReadPdo());
            $this->assertSame($oldWritePath, $connection->getDatabaseName());
            $this->assertSame($oldWritePath, $connection->getConfig('database'));
            $this->assertSame('Old Read', $connection->selectOne('select name from users')->name);
            $this->assertSame('Old Write', $connection->selectFromWriteConnection('select name from users')[0]->name);
            $this->assertSame([], $establishedConnections);
        } finally {
            $connection?->disconnect();
            $filesystem->deleteDirectory($directory);
        }
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
        $filesystem->deleteDirectory($directory);
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

            $manager = $this->db->getDatabaseManager();
            $connection = $manager->connection('split-reconnect::read');
            $this->assertSame('Read Side', $connection->selectOne('select name from users')->name);
            $this->assertSame(['split-reconnect::read'], array_keys($manager->getConnections()));

            $connection->setPdo(null);
            $connection->reconnectIfMissingConnection();

            $this->assertSame('Read Side', $connection->selectOne('select name from users')->name);
            $this->assertSame(['split-reconnect::read'], array_keys($manager->getConnections()));
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
        $filesystem->deleteDirectory($directory);
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

            $manager = $this->db->getDatabaseManager();
            $connection = $manager->connection('split-write-reconnect::write');
            $this->assertSame('Write Side', $connection->selectOne('select name from users')->name);
            $this->assertSame(['split-write-reconnect::write'], array_keys($manager->getConnections()));

            $connection->setPdo(null);
            $connection->reconnectIfMissingConnection();

            $this->assertSame('Write Side', $connection->selectOne('select name from users')->name);
            $this->assertSame(['split-write-reconnect::write'], array_keys($manager->getConnections()));
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
        $this->assertInstanceOf(PdoConnection::class, $connection);
        $readPdoResolver = $connection->getRawReadPdo();
        $this->assertInstanceOf(Closure::class, $readPdoResolver);
        $connection->statement('create table users (id integer primary key, name varchar)');
        $connection->insert('insert into users (name) values (?)', ['Taylor']);

        $this->assertSame('split', $connection->getName());
        $this->assertSame('write', $connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        $this->assertSame("'value'", $connection->escape('value'));
        $this->assertSame($readPdoResolver, $connection->getRawReadPdo());
        $this->assertSame('Taylor', $connection->selectOne('select name from users')->name);
    }

    public function testReadAndWriteSuffixesRetainTheirRolesForUnsplitConnections(): void
    {
        $manager = $this->db->getDatabaseManager();

        $read = $manager->connection('default::read');
        $write = $manager->connection('default::write');

        $this->assertInstanceOf(Connection::class, $read);
        $this->assertInstanceOf(Connection::class, $write);
        $this->assertSame('default', $read->getName());
        $this->assertSame('default', $write->getName());
        $this->assertSame('read', $read->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        $this->assertSame('write', $write->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
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
