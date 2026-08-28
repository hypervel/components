<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\PdoConnection;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use LogicException;
use Mockery as m;
use PDO;
use RuntimeException;

class RefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithConsole;

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

    public function testRefreshTestDatabaseDefault()
    {
        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => false,
                '--drop-types' => false,
                '--seed' => false,
            ])->andReturn(0);

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
    }

    public function testRefreshTestDatabaseWithDropViewsOption()
    {
        $this->dropViews = true;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => true,
                '--drop-types' => false,
                '--seed' => false,
            ])->andReturn(0);
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
    }

    public function testRefreshTestDatabaseWithDropTypesOption()
    {
        $this->dropTypes = true;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => false,
                '--drop-types' => true,
                '--seed' => false,
            ])->andReturn(0);
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
    }

    public function testRefreshTestDatabaseWithSeedOption()
    {
        $this->seed = true;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => false,
                '--drop-types' => false,
                '--seed' => true,
            ])->andReturn(0);
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
    }

    public function testRefreshTestDatabaseWithSeederOption()
    {
        $this->seeder = 'seeder';

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => false,
                '--drop-types' => false,
                '--seeder' => 'seeder',
            ])->andReturn(0);
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
    }

    public function testRefreshTestDatabaseRestoresMockConsoleOutputAfterMigrationFailure(): void
    {
        $this->mockConsoleOutput = true;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->andThrow(new RuntimeException('Migration failed.'));

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

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', m::type('array'))
            ->andReturn(0);

        $pdo = m::mock(PDO::class);
        $eventDispatcher = m::mock(Dispatcher::class);
        $connection = m::mock(PdoConnection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('setTransactionManager')->once();
        $connection->shouldReceive('getEventDispatcher')->twice()->andReturn($eventDispatcher);
        $connection->shouldReceive('unsetEventDispatcher')->twice();
        $connection->shouldReceive('beginTransaction')->once();
        $connection->shouldReceive('inTransaction')->once()->andReturnTrue();
        $connection->shouldReceive('forgetRecordModificationState')->once();
        $connection->shouldReceive('rollBack')->once();
        $connection->shouldReceive('setEventDispatcher')->twice()->with($eventDispatcher);

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->times(3)->with(null)->andReturn($connection);

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

        $pdo = m::mock(PDO::class);
        $connection = m::mock(PdoConnection::class);
        $connection->shouldReceive('getPdo')->once()->andReturnUsing(function () use ($pdo) {
            $this->assertFalse(RefreshDatabaseState::$migrated);

            return $pdo;
        });

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', m::type('array'))
            ->andReturn(0);

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
        $pdo = m::mock(PDO::class);
        $eventDispatcher = m::mock(Dispatcher::class);
        $connection = m::mock(PdoConnection::class);
        $connection->shouldReceive('setPdo')->once()->with($pdo)->andReturnSelf();
        $connection->shouldReceive('setEventDispatcher')->once()->with($eventDispatcher)->andReturnSelf();

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);

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

        $memoryPdo = m::mock(PDO::class);
        $memoryConnection = m::mock(PdoConnection::class);
        $memoryConnection->shouldReceive('getPdo')->once()->andReturn($memoryPdo);

        $database = m::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection')->with('file');
        $database->shouldReceive('connection')->once()->with('memory')->andReturn($memoryConnection);

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', m::type('array'))
            ->andReturn(0);

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

        $connection = m::mock(ConnectionInterface::class);

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', m::type('array'))
            ->andReturn(0);

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
        $this->expectExceptionMessage('In-memory SQLite database testing requires a PDO-backed connection.');

        $this->refreshTestDatabase();
    }

    public function testRestoreInMemoryDatabaseRequiresPdoConnection(): void
    {
        $pdo = m::mock(PDO::class);
        $connection = m::mock(ConnectionInterface::class);
        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);
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
        $this->expectExceptionMessage('In-memory SQLite database testing requires a PDO-backed connection.');

        $this->restoreInMemoryDatabase();
    }

    public function testRefreshDatabaseRemigratesWhenAnInMemoryPdoCacheIsMissing(): void
    {
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->migrateRefresh = false;
        $this->runTestsInCoroutine = true;

        $pdo = m::mock(PDO::class);
        $connection = m::mock(PdoConnection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', m::type('array'))
            ->andReturn(0);

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
        $pdo = m::mock(PDO::class);
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
        $oldPdo = m::mock(PDO::class);
        $freshPdo = m::mock(PDO::class);
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = ['default' => $oldPdo];
        $this->migrateRefresh = true;
        $this->runTestsInCoroutine = true;

        $connection = m::mock(PdoConnection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($freshPdo);

        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', m::type('array'))
            ->andReturn(0);

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

    protected function getMockedDatabase(): DatabaseManager
    {
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('getEventDispatcher')
            ->twice()
            ->andReturn($eventDispatcher = m::mock(Dispatcher::class));
        $connection->shouldReceive('unsetEventDispatcher')
            ->twice();
        $connection->shouldReceive('beginTransaction')
            ->once();
        $connection->shouldReceive('rollback')
            ->once();
        $connection->shouldReceive('setEventDispatcher')
            ->twice()
            ->with($eventDispatcher);
        $connection->shouldReceive('setTransactionManager')
            ->once();

        $connection->shouldReceive('inTransaction')
            ->once()
            ->andReturnTrue();

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('connection')
            ->twice()
            ->with(null)
            ->andReturn($connection);

        return $db;
    }
}
