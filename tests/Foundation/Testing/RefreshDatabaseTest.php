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

        RefreshDatabaseState::$migrated = false;

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
