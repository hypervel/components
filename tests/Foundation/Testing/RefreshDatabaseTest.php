<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PDO;

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

    public function tearDown(): void
    {
        $this->dropViews = false;
        $this->dropTypes = false;
        $this->seed = false;
        $this->seeder = null;

        ResetRefreshDatabaseState::run();

        parent::tearDown();
    }

    protected function setUpTraits(): array
    {
        return [];
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
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
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
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
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
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
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
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
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
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);
        $this->app->singleton('db', fn () => $this->getMockedDatabase());

        $this->refreshTestDatabase();
    }

    public function testBeginDatabaseTransactionWorkSetsMigratedAndCachesPdoTogether()
    {
        // Regression test for the RefreshDatabase + RunTestsInCoroutine +
        // mid-setUp skip bug. The invariant the fix establishes is that
        // RefreshDatabaseState::$migrated must only become true once the
        // in-memory PDO has been cached — both pieces of state update in
        // lockstep inside beginDatabaseTransactionWork().

        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        $pdo = m::mock(PDO::class);
        $eventDispatcher = m::mock(Dispatcher::class);

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('setTransactionManager')->once();
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('getEventDispatcher')->once()->andReturn($eventDispatcher);
        $connection->shouldReceive('unsetEventDispatcher')->once();
        $connection->shouldReceive('beginTransaction')->once();
        $connection->shouldReceive('setEventDispatcher')->once()->with($eventDispatcher);

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('connection')->with(null)->andReturn($connection);

        $this->app = new Application;
        $this->app->singleton('config', fn () => new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton('db', fn () => $db);

        $this->beginDatabaseTransactionWork();

        $this->assertTrue(
            RefreshDatabaseState::$migrated,
            '$migrated must be true after beginDatabaseTransactionWork() runs',
        );
        $this->assertCount(
            1,
            RefreshDatabaseState::$inMemoryConnections,
            'in-memory PDO cache must be populated alongside $migrated',
        );
        $this->assertSame(
            $pdo,
            reset(RefreshDatabaseState::$inMemoryConnections),
            'cached PDO must match the one returned by the connection',
        );
    }

    public function testRefreshTestDatabaseLeavesMigratedFalseWhenTransactionWorkNotYetRun()
    {
        // Regression test for the skip-window scenario: a RunTestsInCoroutine
        // test that runs migrate:fresh in refreshTestDatabase() and then
        // skips before invokeTestMethod() (which calls
        // beginDatabaseTransactionWork) must NOT leave $migrated=true with
        // an empty PDO cache — that would poison the next test, which would
        // skip its own migration and try to restore from an empty cache.

        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        // Do NOT use the class-level $migrateRefresh=true override; we
        // want migrate:fresh to run because $migrated is false, not because
        // $migrateRefresh forces it.
        $this->migrateRefresh = false;

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
                    'default' => ['database' => ':memory:'],
                ],
            ],
        ]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);

        $this->refreshTestDatabase();

        $this->assertFalse(
            RefreshDatabaseState::$migrated,
            '$migrated must stay false until beginDatabaseTransactionWork() has cached the PDO',
        );
        $this->assertSame(
            [],
            RefreshDatabaseState::$inMemoryConnections,
            'in-memory PDO cache must stay empty when beginDatabaseTransactionWork() has not run yet',
        );
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

        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')
            ->andReturn(true);
        $connection->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdo);

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('connection')
            ->twice()
            ->with(null)
            ->andReturn($connection);

        return $db;
    }
}
