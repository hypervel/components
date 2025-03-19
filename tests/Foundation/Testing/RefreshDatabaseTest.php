<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ConnectionInterface;
use Hyperf\DbConnection\Db;
use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Mockery as m;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class RefreshDatabaseTest extends ApplicationTestCase
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
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            Db::class => fn () => $this->getMockedDatabase(),
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
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            Db::class => fn () => $this->getMockedDatabase(),
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
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            Db::class => fn () => $this->getMockedDatabase(),
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
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
            Db::class => fn () => $this->getMockedDatabase(),
        ]);

        $this->refreshTestDatabase();
    }

    protected function getConfig(array $config = []): Config
    {
        return new Config(array_merge([
            'database' => [
                'default' => 'default',
            ],
        ], $config));
    }

    protected function getMockedDatabase(): Db
    {
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('getEventDispatcher')
            ->twice()
            ->andReturn($eventDispatcher = m::mock(EventDispatcherInterface::class));
        $connection->shouldReceive('unsetEventDispatcher')
            ->twice();
        $connection->shouldReceive('beginTransaction')
            ->once();
        $connection->shouldReceive('rollback')
            ->once();
        $connection->shouldReceive('setEventDispatcher')
            ->twice()
            ->with($eventDispatcher);

        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')
            ->andReturn(true);
        $connection->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdo);

        $db = m::mock(Db::class);
        $db->shouldReceive('connection')
            ->twice()
            ->with(null)
            ->andReturn($connection);

        return $db;
    }
}
