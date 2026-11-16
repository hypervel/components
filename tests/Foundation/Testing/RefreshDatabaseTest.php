<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\PdoConnection;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Foundation\Testing\Fixtures\ConsoleKernelSpy;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use LogicException;
use PDO;
use RuntimeException;

class RefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithConsole;
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    protected bool $dropViews = false;

    protected bool $dropTypes = false;

    protected bool $seed = false;

    protected ?string $seeder = null;

    protected bool $migrateRefresh = true;

    protected ?RuntimeException $afterRefreshingDatabaseException = null;

    /**
     * @var list<?string>
     */
    protected array $connectionsToTransact = [null];

    public function tearDown(): void
    {
        $this->dropViews = false;
        $this->dropTypes = false;
        $this->seed = false;
        $this->seeder = null;
        $this->connectionsToTransact = [null];
        $this->afterRefreshingDatabaseException = null;

        ResetRefreshDatabaseState::run();

        parent::tearDown();
    }

    protected function setUpTraits(): array
    {
        return [];
    }

    protected function afterRefreshingDatabase(): void
    {
        if ($this->afterRefreshingDatabaseException !== null) {
            throw $this->afterRefreshingDatabaseException;
        }
    }

    public function testRefreshTestDatabaseDefault(): void
    {
        $kernel = new ConsoleKernelSpy;

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['database' => 'database.sqlite'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $this->getMockedDatabase());

        $this->refreshTestDatabase();

        $this->assertSame([
            ['migrate:fresh', ['--drop-views' => false, '--drop-types' => false, '--seed' => false]],
        ], $kernel->calls);
        $this->assertSame([null], $kernel->setArtisanCalls);
    }

    public function testRefreshTestDatabaseWithDropViewsOption(): void
    {
        $this->dropViews = true;

        $kernel = new ConsoleKernelSpy;
        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['database' => 'database.sqlite'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $this->getMockedDatabase());

        $this->refreshTestDatabase();

        $this->assertSame([
            ['migrate:fresh', ['--drop-views' => true, '--drop-types' => false, '--seed' => false]],
        ], $kernel->calls);
        $this->assertSame([null], $kernel->setArtisanCalls);
    }

    public function testRefreshTestDatabaseWithDropTypesOption(): void
    {
        $this->dropTypes = true;

        $kernel = new ConsoleKernelSpy;
        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['database' => 'database.sqlite'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $this->getMockedDatabase());

        $this->refreshTestDatabase();

        $this->assertSame([
            ['migrate:fresh', ['--drop-views' => false, '--drop-types' => true, '--seed' => false]],
        ], $kernel->calls);
        $this->assertSame([null], $kernel->setArtisanCalls);
    }

    public function testRefreshTestDatabaseWithSeedOption(): void
    {
        $this->seed = true;

        $kernel = new ConsoleKernelSpy;
        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['database' => 'database.sqlite'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $this->getMockedDatabase());

        $this->refreshTestDatabase();

        $this->assertSame([
            ['migrate:fresh', ['--drop-views' => false, '--drop-types' => false, '--seed' => true]],
        ], $kernel->calls);
        $this->assertSame([null], $kernel->setArtisanCalls);
    }

    public function testRefreshTestDatabaseWithSeederOption(): void
    {
        $this->seeder = 'seeder';

        $kernel = new ConsoleKernelSpy;
        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['database' => 'database.sqlite'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $this->getMockedDatabase());

        $this->refreshTestDatabase();

        $this->assertSame([
            ['migrate:fresh', ['--drop-views' => false, '--drop-types' => false, '--seeder' => 'seeder']],
        ], $kernel->calls);
        $this->assertSame([null], $kernel->setArtisanCalls);
    }

    public function testTestTransactionIsRolledBackWhenTheTestLeavesATransactionOpen(): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $this->app->instance(KernelContract::class, new ConsoleKernelSpy);

        $this->refreshTestDatabase();

        $pdo = $this->app->make('db')->connection()->getPdo();

        $this->app->make('db')->connection()->beginTransaction();

        $this->callBeforeApplicationDestroyedCallbacks();

        $this->assertFalse($pdo->inTransaction());
    }

    public function testRefreshTestDatabaseRestoresMockConsoleOutputAfterMigrationFailure(): void
    {
        $this->mockConsoleOutput = true;

        $kernel = Double::for(KernelContract::class);
        $kernel->expects('call')
            ->throws(new RuntimeException('Migration failed.'));

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['database' => 'database.sqlite'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);

        try {
            $this->refreshTestDatabase();
            $this->fail('Expected the migration failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Migration failed.', $exception->getMessage());
            $this->assertTrue($this->mockConsoleOutput);
        }
    }

    public function testRefreshDatabaseDoesNotPublishMigratedWhenTheAfterHookFails(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateRefresh = false;
        $this->runTestsInCoroutine = false;
        $this->afterRefreshingDatabaseException = new RuntimeException('After refresh failed.');

        $kernel = Double::for(KernelContract::class);
        $kernel->expects('call')
            ->with('migrate:fresh', Argument::type('array'))
            ->returns(0);
        $kernel->expects('setArtisan')->with(null);

        $pdo = Double::for(PDO::class);
        $eventDispatcher = Double::for(Dispatcher::class);
        $connection = Double::for(PdoConnection::class);
        $connection->expects('getPdo')->returns($pdo);
        $connection->expects('getNameWithReadWriteType')->returns('default');
        $connection->expects('setTransactionManager');
        $connection->expects('getEventDispatcher')->times(2)->returns($eventDispatcher);
        $connection->expects('unsetEventDispatcher')->times(2);
        $connection->expects('beginTransaction')->resolves(
            fn () => $this->app->make('db.transactions')->begin('default', 1)
        );
        $connection->expects('inTransaction')->returns(true);
        $connection->expects('forgetRecordModificationState');
        $connection->expects('rollBack');
        $connection->expects('setEventDispatcher')->times(2)->with($eventDispatcher);

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->times(3)->with(null)->returns($connection);

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $database);

        try {
            $this->refreshDatabase();
            $this->fail('Expected the after refresh failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->afterRefreshingDatabaseException, $exception);
        }

        $this->assertFalse(RefreshDatabaseState::$migrated);
        $this->assertSame(['default' => $pdo], RefreshDatabaseState::$inMemoryConnections);
    }

    public function testRefreshDatabaseCachesPdoBeforeMarkingTheDatabaseAsMigrated(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->migrateRefresh = false;
        $this->runTestsInCoroutine = true;

        $pdo = Double::for(PDO::class);
        $connection = Double::for(PdoConnection::class);
        $connection->expects('getPdo')->resolves(function () use ($pdo): PDO {
            $this->assertFalse(RefreshDatabaseState::$migrated);

            return $pdo;
        });

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);

        $kernel = Double::for(KernelContract::class);
        $kernel->expects('call')
            ->with('migrate:fresh', Argument::type('array'))
            ->returns(0);
        $kernel->expects('setArtisan')->with(null);

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $database);

        $this->refreshDatabase();

        $this->assertTrue(RefreshDatabaseState::$migrated);
        $this->assertSame(
            ['default' => $pdo],
            RefreshDatabaseState::$inMemoryConnections,
        );
    }

    public function testRestoreInMemoryDatabaseUsesResolvedDefaultConnectionName(): void
    {
        $pdo = Double::for(PDO::class);
        $eventDispatcher = Double::for(Dispatcher::class);
        $connection = Double::for(PdoConnection::class);
        $connection->expects('setPdo')->with($pdo);
        $connection->expects('setEventDispatcher')->with($eventDispatcher);

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);

        RefreshDatabaseState::$inMemoryConnections = ['default' => $pdo];

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton('db', fn () => $database);
        $this->app->singleton('events', fn () => $eventDispatcher);

        $this->restoreInMemoryDatabase();
    }

    public function testInMemoryClassificationUsesTheNamedConnectionAndLiveDefault(): void
    {
        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'file',
                'connections' => [
                    'file' => [
                        'driver' => 'sqlite',
                        'database' => ParallelTesting::tempDir('RefreshDatabaseTest')
                            . '/database.sqlite',
                    ],
                    'memory' => ['driver' => 'sqlite', 'database' => 'file::memory:?cache=shared'],
                    'url_memory' => ['url' => 'sqlite:///:memory:'],
                ],
            ],
        ]));

        $this->assertFalse($this->usingInMemoryDatabase());
        $this->assertFalse($this->usingInMemoryDatabase('file'));
        $this->assertTrue($this->usingInMemoryDatabase('memory'));
        $this->assertTrue($this->usingInMemoryDatabase('url_memory'));

        $this->connectionsToTransact = ['file', 'memory'];

        $this->assertTrue($this->usingInMemoryDatabases());

        $this->connectionsToTransact = ['file'];

        $this->assertFalse($this->usingInMemoryDatabases());
    }

    public function testRefreshTestDatabaseCachesOnlyNamedInMemoryConnections(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->migrateRefresh = false;
        $this->runTestsInCoroutine = true;

        $memoryPdo = Double::for(PDO::class);
        $memoryConnection = Double::for(PdoConnection::class);
        $memoryConnection->expects('getPdo')->returns($memoryPdo);

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->never()->with('file');
        $database->expects('connection')->with('memory')->returns($memoryConnection);

        $kernel = Double::for(KernelContract::class);
        $kernel->expects('call')
            ->with('migrate:fresh', Argument::type('array'))
            ->returns(0);
        $kernel->expects('setArtisan')->with(null);

        $this->connectionsToTransact = ['file', 'memory'];
        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'file',
                'connections' => [
                    'file' => [
                        'driver' => 'sqlite',
                        'database' => ParallelTesting::tempDir('RefreshDatabaseTest')
                            . '/database.sqlite',
                    ],
                    'memory' => ['driver' => 'sqlite', 'database' => 'file::memory:?cache=shared'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $database);

        $this->refreshTestDatabase();

        $this->assertFalse(RefreshDatabaseState::$migrated);
        $this->assertSame(
            ['memory' => $memoryPdo],
            RefreshDatabaseState::$inMemoryConnections,
        );
    }

    public function testRefreshTestDatabaseRequiresPdoForInMemoryConnection(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->migrateRefresh = false;
        $this->runTestsInCoroutine = true;

        $connection = Double::for(Connection::class);

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);

        $kernel = Double::for(KernelContract::class);
        $kernel->expects('call')
            ->with('migrate:fresh', Argument::type('array'))
            ->returns(0);
        $kernel->expects('setArtisan')->with(null);

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $database);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('In-memory SQLite database testing requires a PDO-backed connection.');

        $this->refreshTestDatabase();
    }

    public function testRestoreInMemoryDatabaseRequiresPdoConnection(): void
    {
        $pdo = Double::for(PDO::class);
        $connection = Double::for(Connection::class);
        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);
        RefreshDatabaseState::$inMemoryConnections = ['default' => $pdo];

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton('db', fn () => $database);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('In-memory SQLite database testing requires a PDO-backed connection.');

        $this->restoreInMemoryDatabase();
    }

    public function testRefreshDatabaseRemigratesWhenAnInMemoryPdoCacheIsMissing(): void
    {
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->migrateRefresh = false;
        $this->runTestsInCoroutine = true;

        $pdo = Double::for(PDO::class);
        $connection = Double::for(PdoConnection::class);
        $connection->expects('getPdo')->returns($pdo);

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);

        $kernel = Double::for(KernelContract::class);
        $kernel->expects('call')
            ->with('migrate:fresh', Argument::type('array'))
            ->returns(0);
        $kernel->expects('setArtisan')->with(null);

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $database);

        $this->refreshDatabase();

        $this->assertTrue(RefreshDatabaseState::$migrated);
        $this->assertSame(['default' => $pdo], RefreshDatabaseState::$inMemoryConnections);
    }

    public function testRefreshTestDatabaseSkipsMigrationWhenTheSchemaAndPdoCacheAreReady(): void
    {
        $pdo = Double::for(PDO::class);
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = ['default' => $pdo];
        $this->migrateRefresh = false;
        $this->runTestsInCoroutine = true;

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));

        $this->refreshTestDatabase();

        $this->assertFalse($this->app->resolved(KernelContract::class));
        $this->assertFalse($this->app->resolved('db'));
        $this->assertSame(['default' => $pdo], RefreshDatabaseState::$inMemoryConnections);
    }

    public function testMigrateRefreshReplacesTheCachedInMemoryPdo(): void
    {
        $oldPdo = Double::for(PDO::class);
        $freshPdo = Double::for(PDO::class);
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = ['default' => $oldPdo];
        $this->migrateRefresh = true;
        $this->runTestsInCoroutine = true;

        $connection = Double::for(PdoConnection::class);
        $connection->expects('getPdo')->returns($freshPdo);

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);

        $kernel = Double::for(KernelContract::class);
        $kernel->expects('call')
            ->with('migrate:fresh', Argument::type('array'))
            ->returns(0);
        $kernel->expects('setArtisan')->with(null);

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $database);

        $this->refreshTestDatabase();

        $this->assertFalse($this->migrateRefresh);
        $this->assertSame(['default' => $freshPdo], RefreshDatabaseState::$inMemoryConnections);
    }

    public function testBeginTransactionRestoresTheDispatcherWhenItFails(): void
    {
        $failure = new RuntimeException('Transaction begin failed.');
        $dispatcher = Double::for(Dispatcher::class);
        $connection = Double::for(Connection::class);
        $connection->expects('setTransactionManager');
        $connection->expects('getEventDispatcher')->returns($dispatcher);
        $connection->expects('unsetEventDispatcher')->ordered();
        $connection->expects('beginTransaction')->throws($failure)->ordered();
        $connection->expects('setEventDispatcher')->with($dispatcher)->ordered();

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);
        $this->app->instance('db', $database);

        try {
            $this->beginDatabaseTransactionWork();
            $this->fail('Expected the transaction begin failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testRollbackRestoresTheDispatcherAndKeepsMigrationBookkeepingWhenItFails(): void
    {
        RefreshDatabaseState::$migrated = true;
        $failure = new RuntimeException('Transaction rollback failed.');
        $dispatcher = Double::for(Dispatcher::class);
        $connection = Double::for(PdoConnection::class);
        $connection->expects('getEventDispatcher')->returns($dispatcher);
        $connection->expects('unsetEventDispatcher')->ordered();
        $connection->expects('inTransaction')->returns(false)->ordered();
        $connection->expects('forgetRecordModificationState')->ordered();
        $connection->expects('rollBack')->throws($failure)->ordered();
        $connection->expects('setEventDispatcher')->with($dispatcher)->ordered();

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')->with(null)->returns($connection);
        $this->app->instance('db', $database);

        try {
            $this->rollbackDatabaseTransactionWork();
            $this->fail('Expected the transaction rollback failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertFalse(RefreshDatabaseState::$migrated);
    }

    /**
     * Create a database mock for the transaction lifecycle.
     */
    protected function getMockedDatabase(): DatabaseManager
    {
        $connection = Double::for(Connection::class);
        $connection->expects('getNameWithReadWriteType')->returns('default');
        $eventDispatcher = Double::for(Dispatcher::class);
        $connection->expects('getEventDispatcher')
            ->times(2)
            ->returns($eventDispatcher);
        $connection->expects('unsetEventDispatcher')
            ->times(2);
        $connection->expects('beginTransaction')->resolves(
            fn () => $this->app->make('db.transactions')->begin('default', 1)
        );
        $connection->expects('forgetRecordModificationState');
        $connection->expects('rollback');
        $connection->expects('setEventDispatcher')
            ->times(2)
            ->with($eventDispatcher);
        $connection->expects('setTransactionManager');

        $connection->expects('inTransaction')
            ->returns(true);

        $database = Double::for(DatabaseManager::class);
        $database->expects('connection')
            ->times(2)
            ->with(null)
            ->returns($connection);

        return $database;
    }
}
