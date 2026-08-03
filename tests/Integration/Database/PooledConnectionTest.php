<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Closure;
use Exception;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
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

        try {
            $this->app->make('config')->set('database.connections.file_read_pool_test', [
                'driver' => 'sqlite',
                'read' => [
                    'database' => $readPath,
                ],
                'write' => [
                    'database' => $writePath,
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
            $this->assertSame('read', $connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));

            $pooledConnection->release();
        } finally {
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
        Connection::configureSessionUsing($configurator);
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
        Connection::configureSessionUsing($configurator);
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
        Connection::configureSessionUsing($configurator);
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
        Connection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $connection = $pooledConnection->getConnection();
            $readPdo = new PDO('sqlite::memory:');
            $connection->setReadPdo($readPdo);
            $configurator->desiredState = 'fail';
            $configurator->applyCallback = static fn () => throw new Exception('Configuration failed.');

            try {
                $connection->getReadPdo();
                $this->fail('Expected configuration exception was not thrown.');
            } catch (Exception) {
            }

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
        Connection::configureSessionUsing($configurator);
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
        $configurator->applyCallback = static fn () => throw new Exception('Configuration failed.');
        Connection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'session_reconnect_test');
        $pooledConnection = null;

        try {
            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();

            try {
                $connection->getPdo();
                $this->fail('Expected configuration exception was not thrown.');
            } catch (Exception) {
            }

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
        Connection::configureSessionUsing($configurator);
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

    public function testSharedInMemorySqliteUnknownRecoveryIsBoundedAndFailsClosed(): void
    {
        $configurator = new PoolSessionConfigurator;
        Connection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'pool_test');

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $connection = $pooledConnection->getConnection();
            $sharedPdo = $connection->getPdo();
            $configurator->desiredState = 'fail';
            $configurator->applyCallback = static fn () => throw new Exception('Configuration failed.');

            try {
                $connection->getPdo();
                $this->fail('Expected configuration exception was not thrown.');
            } catch (Exception) {
            }

            $pooledConnection->release();
            $pooledConnection = null;
            $configurator->applyCallback = null;

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $pool->get();
            $replacementConnection = $pooledConnection->getConnection();
            $connectionEstablished = 0;
            $this->app->make(Dispatcher::class)->listen(
                ConnectionEstablished::class,
                static function () use (&$connectionEstablished): void {
                    ++$connectionEstablished;
                }
            );

            try {
                $replacementConnection->getPdo();
                $this->fail('Expected unknown session exception was not thrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Database session state remains unknown after reconnecting.', $exception->getMessage());
            }

            $this->assertSame($sharedPdo, $replacementConnection->getRawPdo());
            $this->assertSame(1, $connectionEstablished);
            $this->assertSame(2, $configurator->applyCalls);
        } finally {
            $pooledConnection?->release();
            $pool->close();
        }
    }

    public function testHeartbeatDoesNotComputeOrInvalidateSessionState(): void
    {
        $configurator = new PoolSessionConfigurator;
        Connection::configureSessionUsing($configurator);
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
        // factory->make() path (not the makeSqliteFromSharedPdo() path
        // that in-memory SQLite uses).
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('PooledConnectionTest-extension');
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $databasePath = $directory . '/extension.sqlite';
        touch($databasePath);

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

            $custom = new SQLiteConnection(
                new PDO('sqlite::memory:'),
                ':memory:',
                '',
                ['name' => 'extension_test']
            );

            /** @var ConnectionFactory $factory */
            $factory = $this->app->make('db.factory');
            $factory->extend('sqlite', fn () => $custom);

            $pool = new DbPool($this->app, 'extension_test');
            $pooledConnection = $this->createPooledConnectionForName($pool, 'extension_test');

            // reconnect() calls factory->make() which should consult the extension
            $this->assertSame($custom, $pooledConnection->getConnection());
        } finally {
            $filesystem->deleteDirectory($directory);
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

    public function state(Connection $connection): ?string
    {
        ++$this->stateCalls;

        return $connection->getName() === $this->connectionName
            ? $this->desiredState
            : null;
    }

    public function apply(PDO $pdo, string $state, Connection $connection): void
    {
        ++$this->applyCalls;

        if ($this->applyCallback instanceof Closure) {
            ($this->applyCallback)($pdo, $state, $connection);
        }
    }
}
