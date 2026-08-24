<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Closure;
use Exception;
use Generator;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Pool\Events\ReleaseConnection;
use Hypervel\Pool\PoolOption;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use PDO;
use ReflectionProperty;
use RuntimeException;

/**
 * Tests for PooledConnection — the adapter that wraps a database Connection
 * for use with Hypervel's connection pool infrastructure.
 *
 * Uses in-memory SQLite via the pool to avoid requiring an external database.
 */
class PooledConnectionTest extends DatabaseTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        // Suppress expected log output from transaction rollback tests
        $app->make('config')->set('app.stdout_log.level', []);

        $app->make('config')->set('database.connections.pool_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => -1.0,
            ],
        ]);
    }

    public function testConstructorSetsEventDispatcher(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $dispatcher = new ReflectionProperty(PooledConnection::class, 'dispatcher');

        $this->assertNotNull(
            $dispatcher->getValue($pooledConnection),
            'PooledConnection should resolve the event dispatcher from the container'
        );
        $this->assertInstanceOf(Dispatcher::class, $dispatcher->getValue($pooledConnection));
    }

    public function testConnectionEstablishedEventFiredOnConstruction(): void
    {
        $fired = false;
        $this->app->make(Dispatcher::class)->listen(
            ConnectionEstablished::class,
            function (ConnectionEstablished $event) use (&$fired) {
                $fired = true;
                $this->assertSame('pool_test', $event->connectionName);
            }
        );

        $pool = new DbPool($this->app, 'pool_test');
        $this->createPooledConnection($pool);

        $this->assertTrue($fired, 'ConnectionEstablished event should be fired when a pooled connection is created');
    }

    public function testGetConnectionReturnsConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $connection = $pooledConnection->getConnection();

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testPoolParsesUrlConfigurationBeforeCreatingConnection(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-url');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $databasePath = $directory . '/database.sqlite';
        touch($databasePath);

        try {
            $this->app->make('config')->set('database.connections.url_pool_test', [
                'url' => 'sqlite:///' . $databasePath,
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 1,
                    'heartbeat' => -1,
                ],
            ]);

            $pool = new DbPool($this->app, 'url_pool_test');

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();

            $this->assertSame('sqlite', $connection->getConfig('driver'));
            $this->assertSame($databasePath, $connection->getConfig('database'));

            $pooledConnection->release();
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testDerivedReadPoolForInMemorySqliteIsRejected(): void
    {
        $this->app->make('config')->set('database.connections.memory_read_pool_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => [
                'database' => ':memory:',
            ],
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Database connection [memory_read_pool_test::read] cannot use a derived read pool for in-memory SQLite.'
        );

        new DbPool($this->app, 'memory_read_pool_test::read');
    }

    public function testDerivedReadPoolForInMemorySqliteReadUrlIsRejected(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-read-url-memory');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $writePath = $directory . '/write.sqlite';
        touch($writePath);

        try {
            $this->app->make('config')->set('database.connections.memory_read_url_pool_test', [
                'driver' => 'sqlite',
                'database' => $writePath,
                'read' => [
                    'url' => 'sqlite:///:memory:',
                ],
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 1,
                    'heartbeat' => -1,
                ],
            ]);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage(
                'Database connection [memory_read_url_pool_test::read] cannot use a derived read pool for in-memory SQLite.'
            );

            new DbPool($this->app, 'memory_read_url_pool_test::read');
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testDerivedReadPoolForFileBackedSqliteUsesReadConfig(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-file-read');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $readPath = $directory . '/read.sqlite';
        $writePath = $directory . '/write.sqlite';
        touch($readPath);
        touch($writePath);
        $pool = null;
        $pooledConnection = null;

        try {
            $this->app->make('config')->set('database.connections.file_read_pool_test', [
                'driver' => 'sqlite',
                'prefix' => 'base_',
                'read' => [
                    'database' => $readPath,
                    'prefix' => 'read_',
                ],
                'write' => [
                    'database' => $writePath,
                    'prefix' => 'write_',
                ],
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 1,
                    'heartbeat' => -1,
                ],
            ]);

            $pool = new DbPool($this->app, 'file_read_pool_test::read');

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();

            $this->assertSame('file_read_pool_test', $connection->getName());
            $this->assertSame($readPath, $connection->getConfig('database'));
            $this->assertSame($readPath, $connection->getDatabaseName());
            $this->assertSame('read_', $connection->getTablePrefix());
            $this->assertSame('read', $connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));

            $connection->setDatabaseName('tenant_database');
            $connection->setTablePrefix('tenant_');
            $releasedConnection = $pooledConnection;
            $pooledConnection->release();
            $pooledConnection = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();

            $this->assertSame($releasedConnection, $pooledConnection);
            $this->assertSame($readPath, $connection->getDatabaseName());
            $this->assertSame('read_', $connection->getTablePrefix());
            $this->assertSame('read', $connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        } finally {
            $pooledConnection?->release();
            $pool?->close();
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testGetConnectionReturnsSameInstanceWhileValid(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $first = $pooledConnection->getConnection();
        $second = $pooledConnection->getConnection();

        $this->assertSame($first, $second);
    }

    public function testConnectionEstablishedEventFiredOnReconnect(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $count = 0;
        $this->app->make(Dispatcher::class)->listen(
            ConnectionEstablished::class,
            function () use (&$count) {
                ++$count;
            }
        );

        // reconnect() should fire ConnectionEstablished again
        $pooledConnection->reconnect();

        $this->assertSame(1, $count, 'ConnectionEstablished should fire on reconnect');
    }

    public function testReconnectCreatesNewConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $before = $pooledConnection->getConnection();
        $pooledConnection->reconnect();
        $after = $pooledConnection->getConnection();

        // For in-memory SQLite with shared PDO, the Connection object is
        // different but they share the same PDO
        $this->assertNotSame($before, $after);
    }

    public function testReconnectSetsEventDispatcherOnConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $connection = $pooledConnection->getConnection();
        $dispatcher = $connection->getEventDispatcher();

        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
    }

    public function testCheckReturnsFalseWhenNoConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $pooledConnection->close();

        $this->assertFalse($pooledConnection->check());
    }

    public function testCheckReturnsTrueForFreshConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $this->assertTrue($pooledConnection->check());
    }

    public function testCloseDisconnectsAndNullsConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $result = $pooledConnection->close();

        $this->assertTrue($result);
        $this->assertFalse($pooledConnection->check());
    }

    public function testGetActiveConnectionReconnectsWhenStale(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);

        $pooledConnection->close();

        // getActiveConnection should trigger reconnect
        $connection = $pooledConnection->getActiveConnection();

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testReleaseResetsConnectionState(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        // Get a connection through the pool to test proper release
        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        $connection = $pooledConnection->getConnection();

        // Add some state that should be reset
        $connection->beforeExecuting(function () {});

        $pooledConnection->release();

        // After release, getting the connection again from pool should work
        /** @var PooledConnection $newPooledConnection */
        $newPooledConnection = $pool->get();
        $this->assertInstanceOf(Connection::class, $newPooledConnection->getConnection());
        $newPooledConnection->release();
    }

    public function testReleaseRollsBackOpenTransactions(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $connection = $pooledConnection->getConnection();

        // Create a table and start a transaction
        $connection->getSchemaBuilder()->create('test_rollback', function ($table) {
            $table->id();
            $table->string('name');
        });

        $connection->beginTransaction();
        $connection->table('test_rollback')->insert(['name' => 'should_be_rolled_back']);

        $this->assertSame(1, $connection->transactionLevel());

        // Release should roll back
        $pooledConnection->release();

        // Get a new connection and verify the data was rolled back
        /** @var PooledConnection $newPooledConnection */
        $newPooledConnection = $pool->get();
        $newConnection = $newPooledConnection->getConnection();

        $this->assertSame(0, $newConnection->transactionLevel());
        $this->assertSame(0, $newConnection->table('test_rollback')->count());

        $newPooledConnection->release();
    }

    public function testCleanReleasePreservesMatchingPhysicalSessionState(): void
    {
        $configurator = new PoolSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $firstPooledConnection = $pooledConnection;
            $connection = $firstPooledConnection->getConnection();
            $pdo = $connection->getPdo();
            $applyCalls = $configurator->applyCalls;
            $pooledConnection->release();
            $pooledConnection = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $nextConnection = $pooledConnection->getConnection();

            $this->assertSame($firstPooledConnection, $pooledConnection);
            $this->assertSame($connection, $nextConnection);
            $this->assertSame($pdo, $nextConnection->getPdo());
            $this->assertSame($applyCalls, $configurator->applyCalls);

            $configurator->desiredState = 'changed';
            $nextConnection->getPdo();

            $this->assertSame($applyCalls + 1, $configurator->applyCalls);
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testAbandonedTransactionRollbackInvalidatesPhysicalSessionState(): void
    {
        $configurator = new PoolSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $connection = $pooledConnection->getConnection();
            $connection->beginTransaction();
            $applyCalls = $configurator->applyCalls;
            $pooledConnection->release();
            $pooledConnection = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $pooledConnection->getConnection()->getPdo();

            $this->assertSame($applyCalls + 1, $configurator->applyCalls);
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testUnknownSessionIsMarkedInvalidAtFinalReleaseBoundary(): void
    {
        $configurator = new PoolSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $configurator->desiredState = 'fail';
            $configurator->applyCallback = static fn () => throw new Exception('Configuration failed.');

            try {
                $pooledConnection->getConnection()->getPdo();
                $this->fail('Expected configuration exception was not thrown.');
            } catch (Exception $exception) {
                $this->assertSame('Configuration failed.', $exception->getMessage());
            }

            $releasedConnection = $pooledConnection;
            $pooledConnection->release();
            $pooledConnection = null;

            $this->assertTrue($this->isInvalid($releasedConnection));
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testUnknownReadSessionIsDetectedWithoutResolvingUnopenedPdos(): void
    {
        $configurator = new PoolSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $connection = $pooledConnection->getConnection();
            $readPdo = new PDO('sqlite::memory:');
            $connection->setReadPdo($readPdo);
            $configurator->desiredState = 'fail';
            $configurationException = new Exception('Configuration failed.');
            $configurator->applyCallback = static fn () => throw $configurationException;
            $caughtException = null;

            try {
                $connection->getReadPdo();
            } catch (Exception $exception) {
                $caughtException = $exception;
            }

            $this->assertSame($configurationException, $caughtException);

            $connection->setPdo(static fn () => throw new Exception('Write PDO must not be resolved.'));
            $releasedConnection = $pooledConnection;
            $pooledConnection->release();
            $pooledConnection = null;

            $this->assertTrue($this->isInvalid($releasedConnection));
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testUnknownStateCaughtByReleaseListenerIsStillMarkedInvalid(): void
    {
        $this->app->make('config')->set('database.connections.pool_test.pool.events', [
            ReleaseConnection::class,
        ]);
        $configurator = new PoolSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');
        $configurator->desiredState = 'fail';
        $configurator->applyCallback = static fn () => throw new Exception('Configuration failed.');
        $this->app->make(Dispatcher::class)->listen(
            ReleaseConnection::class,
            static function (ReleaseConnection $event): void {
                try {
                    $event->connection->getConnection()->getPdo();
                } catch (Exception) {
                }
            }
        );

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $releasedConnection = $pooledConnection;
            $pooledConnection->release();
            $pooledConnection = null;

            $this->assertTrue($this->isInvalid($releasedConnection));
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testInvalidNormalConnectionReconnectsAndConfiguresAFreshPdo(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-session-reconnect');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);
        $databasePath = $directory . '/database.sqlite';
        touch($databasePath);
        $this->app->make('config')->set('database.connections.session_reconnect_test', [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ],
        ]);
        $configurator = new PoolSessionConfigurator('session_reconnect_test');
        $configurationException = new Exception('Configuration failed.');
        $configurator->applyCallback = static fn () => throw $configurationException;
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'session_reconnect_test');
        $pooledConnection = null;

        try {
            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();
            $caughtException = null;

            try {
                $connection->getPdo();
            } catch (Exception $exception) {
                $caughtException = $exception;
            }

            $this->assertSame($configurationException, $caughtException);

            $oldPdo = $connection->getRawPdo();
            $firstPooledConnection = $pooledConnection;
            $pooledConnection->release();
            $pooledConnection = null;
            $configurator->desiredState = 'recovered';
            $configurator->applyCallback = null;

            /** @var PooledConnection $nextPooledConnection */
            $nextPooledConnection = $pool->get();
            $pooledConnection = $nextPooledConnection;
            $newPdo = $nextPooledConnection->getConnection()->getPdo();

            $this->assertSame($firstPooledConnection, $nextPooledConnection);
            $this->assertNotSame($oldPdo, $newPdo);
            $this->assertSame(2, $configurator->applyCalls);
        } finally {
            $pooledConnection?->release();
            $pool->close();
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testLeakedForeignKeySuppressionScopeReconnectsANormalPoolWithoutAConfigurator(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-suppression-reconnect');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);
        $databasePath = $directory . '/database.sqlite';
        touch($databasePath);
        $this->app->make('config')->set('database.connections.suppression_reconnect_test', [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ],
        ]);
        $pool = new DbPool($this->app, 'suppression_reconnect_test');
        $pooledConnection = null;

        try {
            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();
            $oldPdo = $connection->getPdo();
            $connection->beginForeignKeyConstraintSuppression();
            $firstPooledConnection = $pooledConnection;
            $pooledConnection->release();
            $pooledConnection = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $newPdo = $pooledConnection->getConnection()->getPdo();

            $this->assertSame($firstPooledConnection, $pooledConnection);
            $this->assertNotSame($oldPdo, $newPdo);
        } finally {
            $pooledConnection?->release();
            $pool->close();
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testFailedRefreshPreservesTheCurrentGenerationAndMarksItInvalid(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-session-refresh-failure');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);
        $databasePath = $directory . '/database.sqlite';
        touch($databasePath);
        $this->app->make('config')->set('database.connections.session_refresh_failure_test', [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ],
        ]);
        $configurator = new PoolSessionConfigurator('session_refresh_failure_test');
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'session_refresh_failure_test');
        $pooledConnection = null;

        try {
            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();
            $oldPdo = $connection->getPdo();
            $configurationException = new Exception('Replacement configuration failed.');
            $configurator->desiredState = 'failed-refresh';
            $configurator->applyCallback = static fn () => throw $configurationException;

            try {
                $connection->getPdo();
                $this->fail('Expected existing-session configuration exception was not thrown.');
            } catch (Exception $exception) {
                $this->assertSame($configurationException, $exception);
            }

            try {
                $connection->getPdo();
                $this->fail('Expected replacement configuration exception was not thrown.');
            } catch (Exception $exception) {
                $this->assertSame($configurationException, $exception);
            }

            $this->assertSame($oldPdo, $connection->getRawPdo());
            $this->assertNull($connection->getRawReadPdo());
            $this->assertTrue($this->isInvalid($pooledConnection));

            $firstPooledConnection = $pooledConnection;
            $pooledConnection->release();
            $pooledConnection = null;
            $configurator->desiredState = 'recovered';
            $configurator->applyCallback = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $newPdo = $pooledConnection->getConnection()->getPdo();

            $this->assertSame($firstPooledConnection, $pooledConnection);
            $this->assertNotSame($oldPdo, $newPdo);
            $this->assertSame(4, $configurator->applyCalls);
        } finally {
            $pooledConnection?->release();
            $pool->close();
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testSharedInMemorySqliteUnknownSessionFailsClosedWithoutDiscardingTheDatabase(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $connection = $pooledConnection->getConnection();
            $sharedPdo = $connection->getPdo();
            $sharedPdo->exec('create table records (id integer primary key)');
            $sharedPdo->exec('insert into records (id) values (1)');
            $connection->beginForeignKeyConstraintSuppression();

            $pooledConnection->release();
            $pooledConnection = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connectionEstablished = 0;
            $this->app->make(Dispatcher::class)->listen(
                ConnectionEstablished::class,
                static function () use (&$connectionEstablished): void {
                    ++$connectionEstablished;
                }
            );

            try {
                $pooledConnection->getConnection();
                $this->fail('Expected unknown session exception was not thrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The shared in-memory SQLite database session is unknown and its sole connection cannot be replaced without discarding the database.',
                    $exception->getMessage()
                );
            }

            $this->assertSame($sharedPdo, $pool->getSharedInMemorySqlitePdo());
            $this->assertSame(1, (int) $sharedPdo->query('select count(*) from records')->fetchColumn());
            $this->assertSame(0, $connectionEstablished);
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testHeartbeatDoesNotComputeOrInvalidateSessionState(): void
    {
        $configurator = new PoolSessionConfigurator;
        PdoConnection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');
        $stateCallsAfterCreation = $configurator->stateCalls;
        $applyCallsAfterCreation = $configurator->applyCalls;

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $pooledConnection->getConnection()->getPdo();
            $stateCallsBeforePing = $configurator->stateCalls;
            $applyCallsBeforePing = $configurator->applyCalls;

            $this->assertGreaterThanOrEqual($stateCallsAfterCreation, $stateCallsBeforePing);
            $this->assertSame($applyCallsAfterCreation, $applyCallsBeforePing);
            $this->assertTrue($pooledConnection->ping(1.0));
            $this->assertSame($stateCallsBeforePing, $configurator->stateCalls);
            $this->assertSame($applyCallsBeforePing, $configurator->applyCalls);

            $pooledConnection->getConnection()->getPdo();
            $this->assertSame($stateCallsBeforePing + 1, $configurator->stateCalls);
            $this->assertSame($applyCallsBeforePing, $configurator->applyCalls);
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testReleaseDispatchesReleaseEventWhenConfigured(): void
    {
        $this->app->make('config')->set('database.connections.pool_test.pool.events', [
            ReleaseConnection::class,
        ]);

        $pool = new DbPool($this->app, 'pool_test');

        $fired = false;
        $this->app->make(Dispatcher::class)->listen(
            ReleaseConnection::class,
            function () use (&$fired) {
                $fired = true;
            }
        );

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $pooledConnection->release();

        $this->assertTrue($fired, 'ReleaseConnection event should be dispatched when configured');
    }

    public function testReuseCheckDoesNotResetLastUseTime(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $pooledConnection->getConnection();

        $initialTime = $pooledConnection->getLastUseTime();

        $pooledConnection->release();

        usleep(10000); // 10ms

        /** @var PooledConnection $nextPooledConnection */
        $nextPooledConnection = $pool->get();
        $nextPooledConnection->getConnection();

        $this->assertSame($pooledConnection, $nextPooledConnection);
        $this->assertSame($initialTime, $nextPooledConnection->getLastUseTime());

        $nextPooledConnection->release();
    }

    public function testInvalidConnectionReconnectsEvenWithFreshReleaseTime(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);
        $originalConnection = $pooledConnection->getConnection();

        (new ReflectionProperty(PooledConnection::class, 'invalid'))->setValue($pooledConnection, true);
        (new ReflectionProperty(PooledConnection::class, 'lastReleaseTime'))->setValue($pooledConnection, hrtime(true) / 1e9);

        $this->assertNotSame($originalConnection, $pooledConnection->getActiveConnection());
    }

    public function testExpiredLifetimeDoesNotReconnectDuringActiveBorrow(): void
    {
        $this->app->make('config')->set('database.connections.pool_test.pool.max_lifetime', 1.0);

        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);
        $originalConnection = $pooledConnection->getConnection();

        $this->assertSame(1.0, $pool->getOption()->getMaxLifetime());

        $originalConnection->beginTransaction();
        $this->ageConnectionGeneration($pooledConnection);

        $this->assertTrue($pooledConnection->check());
        $this->assertSame($originalConnection, $pooledConnection->getActiveConnection());
        $this->assertSame(1, $originalConnection->transactionLevel());

        $originalConnection->rollBack();
    }

    public function testExpiredIdleTimeDoesNotReconnectDuringActiveBorrow(): void
    {
        $this->app->make('config')->set('database.connections.pool_test.pool.max_idle_time', 1.0);

        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);
        $originalConnection = $pooledConnection->getConnection();

        $this->ageActiveConnectionUse($pooledConnection);

        $this->assertTrue($pooledConnection->check());
        $this->assertSame($originalConnection, $pooledConnection->getActiveConnection());
    }

    public function testExpiredLifetimeReconnectsWhenBorrowedFromPoolAgainWithoutHeartbeat(): void
    {
        $this->app->make('config')->set('database.connections.pool_test.pool.max_lifetime', 1.0);

        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $originalConnection = $pooledConnection->getConnection();
        $pooledConnection->release();

        $this->ageConnectionGeneration($pooledConnection);

        /** @var PooledConnection $nextPooledConnection */
        $nextPooledConnection = $pool->get();

        $this->assertSame($pooledConnection, $nextPooledConnection);
        $this->assertNotSame($originalConnection, $nextPooledConnection->getConnection());

        $nextPooledConnection->release();
    }

    public function testDisabledMaxLifetimeDoesNotRecycleAgedConnectionGeneration(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);
        $originalConnection = $pooledConnection->getConnection();

        $this->assertSame(-1.0, $pool->getOption()->getMaxLifetime());

        $this->ageConnectionGeneration($pooledConnection);

        $this->assertFalse($pooledConnection->isLifetimeExpired());
        $this->assertTrue($pooledConnection->check());
        $this->assertSame($originalConnection, $pooledConnection->getActiveConnection());
    }

    public function testPingDoesNotExtendConnectionLifetime(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);
        $pooledConnection->getConnection()->getPdo();

        $createdAt = $pooledConnection->getCreatedAt();

        $this->assertTrue($pooledConnection->ping(1.0));
        $this->assertSame($createdAt, $pooledConnection->getCreatedAt());
    }

    public function testConnectionGenerationLifetimeIsJitteredWithinConfiguredUpperBound(): void
    {
        $this->app->make('config')->set('database.connections.pool_test.pool.max_lifetime', 60.0);

        $pool = new DbPool($this->app, 'pool_test');
        $before = hrtime(true) / 1e9;
        $pooledConnection = $this->createPooledConnection($pool);
        $after = hrtime(true) / 1e9;

        $createdAt = $pooledConnection->getCreatedAt();
        $lifetimeExpiresAt = (new ReflectionProperty(PooledConnection::class, 'lifetimeExpiresAt'))
            ->getValue($pooledConnection);

        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
        $this->assertGreaterThanOrEqual(
            $createdAt + (60.0 * PoolOption::MIN_LIFETIME_JITTER_BASIS / PoolOption::LIFETIME_JITTER_SCALE),
            $lifetimeExpiresAt
        );
        $this->assertLessThanOrEqual($createdAt + 60.0, $lifetimeExpiresAt);
        $this->assertFalse($pooledConnection->isLifetimeExpired($lifetimeExpiresAt - 0.001));
        $this->assertTrue($pooledConnection->isLifetimeExpired($lifetimeExpiresAt));
    }

    public function testConnectionRefreshResetsLifetime(): void
    {
        $pool = new DbPool($this->app, 'pool_test');
        $pooledConnection = $this->createPooledConnection($pool);
        $connection = $pooledConnection->getConnection();

        $this->ageConnectionGeneration($pooledConnection);
        $expiredAt = $pooledConnection->getCreatedAt();

        $connection->reconnect();

        $this->assertGreaterThan($expiredAt, $pooledConnection->getCreatedAt());
    }

    public function testReleaseSnapshotsErrorCountBeforeResettingConnection(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $connection = $pooledConnection->getConnection();

        (new ReflectionProperty(Connection::class, 'errorCount'))->setValue($connection, 101);

        $pooledConnection->release();

        $this->assertSame(0, $connection->getErrorCount());

        /** @var PooledConnection $nextPooledConnection */
        $nextPooledConnection = $pool->get();

        $this->assertNotSame($connection, $nextPooledConnection->getConnection());

        $nextPooledConnection->release();
    }

    public function testReleaseResetsErrorCountForNextBorrowWindow(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();
        $connection = $pooledConnection->getConnection();

        (new ReflectionProperty(Connection::class, 'errorCount'))->setValue($connection, 1);

        $pooledConnection->release();

        $this->assertSame(0, $connection->getErrorCount());

        /** @var PooledConnection $nextPooledConnection */
        $nextPooledConnection = $pool->get();

        $this->assertSame($connection, $nextPooledConnection->getConnection());

        $nextPooledConnection->release();
    }

    public function testSharedPdoPersistsAcrossInMemorySqliteBorrows(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        $this->assertNotNull($pool->getSharedInMemorySqlitePdo());

        /** @var PooledConnection $conn1 */
        $conn1 = $pool->get();
        $pdo1 = $conn1->getConnection()->getPdo();
        $conn1->release();

        /** @var PooledConnection $conn2 */
        $conn2 = $pool->get();
        $pdo2 = $conn2->getConnection()->getPdo();

        $this->assertSame($pdo1, $pdo2, 'In-memory SQLite borrows should share the same PDO');
        $conn2->release();
    }

    public function testSharedPdoDataVisibleAcrossConnections(): void
    {
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $conn1 */
        $conn1 = $pool->get();
        $db1 = $conn1->getConnection();

        $db1->getSchemaBuilder()->create('shared_test', function ($table) {
            $table->id();
            $table->string('value');
        });
        $db1->table('shared_test')->insert(['value' => 'hello']);
        $conn1->release();

        // Second connection should see the same data
        /** @var PooledConnection $conn2 */
        $conn2 = $pool->get();
        $db2 = $conn2->getConnection();

        $this->assertSame(1, $db2->table('shared_test')->count());
        $this->assertSame('hello', $db2->table('shared_test')->value('value'));

        $conn2->release();
    }

    public function testReconnectHonoursFactoryExtensions(): void
    {
        // Use a file-based SQLite connection so reconnect() takes the
        // factory->make() path rather than the shared-PDO path used by
        // pooled in-memory SQLite.
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-extension');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $databasePath = $directory . '/extension.sqlite';
        touch($databasePath);
        $pooledConnection = null;

        try {
            $this->app->make('config')->set('database.connections.extension_test', [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 1,
                    'connect_timeout' => 10.0,
                    'wait_timeout' => 3.0,
                    'heartbeat' => -1,
                    'max_idle_time' => 60.0,
                ],
            ]);

            /** @var ConnectionFactory $factory */
            $factory = $this->app->make('db.factory');
            $resolutions = 0;
            $factory->extend('sqlite', static function (array $config) use (&$resolutions): SQLiteConnection {
                ++$resolutions;

                return new SQLiteConnection(
                    new PDO('sqlite:' . $config['database']),
                    $config['database'],
                    $config['prefix'],
                    $config
                );
            });

            $pool = new DbPool($this->app, 'extension_test');
            $pooledConnection = $this->createPooledConnectionForName($pool, 'extension_test');
            $connection = $pooledConnection->getConnection();
            $firstPdo = $connection->getPdo();

            // Reconnecting through the pool should consult the factory extension.
            $connection->setPdo(null);
            $connection->reconnectIfMissingConnection();

            $this->assertSame($connection, $pooledConnection->getConnection());
            $this->assertNotSame($firstPdo, $connection->getPdo());
            $this->assertSame(2, $resolutions);
        } finally {
            $pooledConnection?->close();
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testConfigFirstNonPdoExtensionSupportsTheCompletePoolLifecycle(): void
    {
        $this->app->make('config')->set('database.connections.neutral_pool_test', [
            'driver' => 'neutral',
            'database' => 'first',
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ],
        ]);

        /** @var ConnectionFactory $factory */
        $factory = $this->app->make('db.factory');
        $resolutions = 0;
        $factory->extend('neutral', static function (array $config) use (&$resolutions): NeutralPoolConnection {
            return new NeutralPoolConnection(++$resolutions, $config['database'], $config['prefix'], $config);
        });

        $pool = new DbPool($this->app, 'neutral_pool_test');
        $pooledConnection = null;

        try {
            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();

            $this->assertInstanceOf(NeutralPoolConnection::class, $connection);
            $this->assertSame(1, $connection->generation);
            $this->assertTrue($pooledConnection->ping(1.0));
            $this->assertSame(1, $connection->pingCalls);

            $connection->dropResources();
            $connection->reconnectIfMissingConnection();

            $this->assertSame($connection, $pooledConnection->getConnection());
            $this->assertSame(2, $connection->generation);
            $this->assertSame(2, $resolutions);
            $this->assertSame(1, $connection->disconnectCalls);

            $pooledConnection->release();
            $pooledConnection = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $this->assertSame($connection, $pooledConnection->getConnection());

            $pooledConnection->release();
            $pooledConnection = null;
            $pool->close();

            $this->assertSame(2, $connection->disconnectCalls);
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testReleaseClearsCapturedMySqlInsertIdBeforeReborrow(): void
    {
        $this->app->make('config')->set('database.connections.mysql_insert_id_pool_test', [
            'driver' => 'mysql_insert_id',
            'database' => 'unused',
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ],
        ]);

        /** @var ConnectionFactory $factory */
        $factory = $this->app->make('db.factory');
        $factory->extend(
            'mysql_insert_id',
            static fn (array $config): PoolMySqlConnection => new PoolMySqlConnection(
                new PDO('sqlite::memory:'),
                $config['database'],
                $config['prefix'],
                $config
            )
        );

        $pool = new DbPool($this->app, 'mysql_insert_id_pool_test');
        $pooledConnection = null;

        try {
            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();
            $this->assertInstanceOf(PoolMySqlConnection::class, $connection);
            $connection->rememberLastInsertId(42);
            $this->assertSame(42, $connection->getLastInsertId());

            $pooledConnection->release();
            $pooledConnection = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $this->assertSame($connection, $pooledConnection->getConnection());

            $exception = null;

            try {
                $connection->getLastInsertId();
            } catch (RuntimeException $runtimeException) {
                $exception = $runtimeException;
            }

            $this->assertNotNull($exception);
            $this->assertSame('No last insert ID has been captured for this connection.', $exception->getMessage());
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    /**
     * Create a PooledConnection directly (bypassing pool.get() for unit-style tests).
     */
    private function createPooledConnection(DbPool $pool): PooledConnection
    {
        return $this->createPooledConnectionForName($pool, 'pool_test');
    }

    /**
     * Create a PooledConnection for a named connection config.
     */
    private function createPooledConnectionForName(DbPool $pool, string $name): PooledConnection
    {
        $config = $this->app->make('config')->get("database.connections.{$name}");
        $config['name'] = $name;

        return new PooledConnection($this->app, $pool, $config);
    }

    private function ageConnectionGeneration(PooledConnection $connection): void
    {
        (new ReflectionProperty(PooledConnection::class, 'createdAt'))->setValue($connection, hrtime(true) / 1e9 - 5.0);

        $lifetimeExpiresAt = new ReflectionProperty(PooledConnection::class, 'lifetimeExpiresAt');

        if ($lifetimeExpiresAt->getValue($connection) > 0.0) {
            $lifetimeExpiresAt->setValue($connection, hrtime(true) / 1e9 - 1.0);
        }
    }

    private function ageActiveConnectionUse(PooledConnection $connection): void
    {
        (new ReflectionProperty(PooledConnection::class, 'lastUseTime'))->setValue($connection, hrtime(true) / 1e9 - 5.0);
    }

    private function isInvalid(PooledConnection $connection): bool
    {
        return (new ReflectionProperty(PooledConnection::class, 'invalid'))->getValue($connection);
    }
}

class PoolSessionConfigurator implements SessionConfigurator
{
    public string $desiredState = 'state';

    public int $stateCalls = 0;

    public int $applyCalls = 0;

    public ?Closure $applyCallback = null;

    public function __construct(
        private readonly string $connectionName = 'pool_test',
    ) {
    }

    public function state(PdoConnection $connection): ?string
    {
        ++$this->stateCalls;

        return $connection->getName() === $this->connectionName
            ? $this->desiredState
            : null;
    }

    public function apply(PDO $pdo, string $state, PdoConnection $connection): void
    {
        ++$this->applyCalls;

        if ($this->applyCallback instanceof Closure) {
            ($this->applyCallback)($pdo, $state, $connection);
        }
    }
}

class NeutralPoolConnection extends Connection
{
    public int $pingCalls = 0;

    public int $disconnectCalls = 0;

    private bool $hasResources = true;

    public function __construct(
        public int $generation,
        string $database,
        string $tablePrefix,
        array $config,
    ) {
        parent::__construct($database, $tablePrefix, $config);
    }

    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return [];
    }

    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator
    {
        yield from [];
    }

    public function statement(string $query, array $bindings = []): bool
    {
        return true;
    }

    public function affectingStatement(string $query, array $bindings = []): int
    {
        return 0;
    }

    public function unprepared(string $query): bool
    {
        return true;
    }

    public function ping(): bool
    {
        ++$this->pingCalls;

        return $this->hasResources;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function getServerVersion(): string
    {
        return 'test';
    }

    public function dropResources(): void
    {
        $this->hasResources = false;
    }

    protected function escapeString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function hasDriverResources(): bool
    {
        return $this->hasResources;
    }

    protected function disconnectDriverResources(): void
    {
        ++$this->disconnectCalls;
        $this->forgetDriverResources();
    }

    protected function forgetDriverResources(): void
    {
        $this->hasResources = false;
    }

    protected function replaceDriverResources(Connection $fresh): void
    {
        /** @var self $fresh */
        $generation = $fresh->generation;
        $hasResources = $fresh->hasResources;

        try {
            $this->disconnectDriverResources();
        } finally {
            $this->generation = $generation;
            $this->hasResources = $hasResources;
        }
    }
}

class PoolMySqlConnection extends MySqlConnection
{
    public function rememberLastInsertId(int|string $lastInsertId): void
    {
        $this->lastInsertId = $lastInsertId;
    }
}
