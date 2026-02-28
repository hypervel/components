<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Mockery as m;
use PDO;

/**
 * @internal
 * @coversNothing
 */
class RefreshDatabaseTest extends TestCase
{
    use HasMockedApplication;
    use RefreshDatabase;
    use InteractsWithConsole;

    protected bool $dropViews = false;

    protected bool $seed = false;

    protected ?string $seeder = null;

    protected bool $migrateRefresh = true;

    public function tearDown(): void
    {
        $this->dropViews = false;
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
                '--database' => 'default',
                '--seed' => false,
            ])->andReturn(0);

        $this->app = $this->getApplication([
            ConfigContract::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            DatabaseManager::class => fn () => $this->getMockedDatabase(),
        ]);

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
                '--database' => 'default',
                '--seed' => false,
            ])->andReturn(0);
        $this->app = $this->getApplication([
            ConfigContract::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            DatabaseManager::class => fn () => $this->getMockedDatabase(),
        ]);

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
                '--database' => 'default',
                '--seed' => true,
            ])->andReturn(0);
        $this->app = $this->getApplication([
            ConfigContract::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            DatabaseManager::class => fn () => $this->getMockedDatabase(),
        ]);

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
                '--database' => 'default',
                '--seeder' => 'seeder',
            ])->andReturn(0);
        $this->app = $this->getApplication([
            ConfigContract::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            DatabaseManager::class => fn () => $this->getMockedDatabase(),
        ]);

        $this->refreshTestDatabase();
    }

    protected function getConfig(array $config = []): Repository
    {
        return new Repository(array_merge([
            'database' => [
                'default' => 'default',
            ],
        ], $config));
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
