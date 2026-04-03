<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationsTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithConsole;

    protected bool $dropViews = false;

    protected bool $dropTypes = false;

    protected bool $seed = false;

    protected ?string $seeder = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->withoutMockingConsoleOutput();
    }

    public function tearDown(): void
    {
        $this->dropViews = false;
        $this->dropTypes = false;
        $this->seed = false;
        $this->seeder = null;
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
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = new Application();
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);

        $this->runDatabaseMigrations();
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
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = new Application();
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);

        $this->runDatabaseMigrations();
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
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = new Application();
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);

        $this->runDatabaseMigrations();
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
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = new Application();
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);

        $this->runDatabaseMigrations();
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
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = new Application();
        $this->app->singleton('config', fn () => new Repository(['database' => ['default' => 'default']]));
        $this->app->singleton(KernelContract::class, fn () => $kernel);

        $this->runDatabaseMigrations();
    }
}
