<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use Workbench\Database\Seeders\TestbenchDatabaseSeeder;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\workbench_path;

/**
 * @internal
 * @coversNothing
 */
class LoadMigrationsFromArrayTest extends TestCase
{
    #[Test]
    public function itCanRegisterMigrations(): void
    {
        $this->instance('migrator', $migrator = m::mock(Migrator::class));

        $paths = [workbench_path('database/migrations')];

        $migrator->shouldReceive('path')->once()->with($paths[0])->andReturnNull()
            ->shouldReceive('path')->once()->with(default_migration_path())->andReturnNull();

        (new LoadMigrationsFromArray($paths))->bootstrap($this->app);
    }

    #[Test]
    public function itCanSkipMigrationsRegistration(): void
    {
        $this->instance('migrator', $migrator = m::mock(Migrator::class));

        $migrator->shouldReceive('path')->never();

        (new LoadMigrationsFromArray(false))->bootstrap($this->app);
    }

    #[Test]
    public function itCanSeedDatabaseAfterRefreshed(): void
    {
        $kernel = m::mock(ConsoleKernel::class);
        $this->app->instance(ConsoleKernel::class, $kernel);

        (new LoadMigrationsFromArray(false, [
            TestbenchDatabaseSeeder::class,
        ]))->bootstrap($this->app);

        $kernel->shouldReceive('call')->once()->with('db:seed', [
            '--class' => TestbenchDatabaseSeeder::class,
        ])->andReturn(0);

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itCanSkipDatabaseSeedingAfterRefreshed(): void
    {
        $kernel = m::mock(ConsoleKernel::class);
        $this->app->instance(ConsoleKernel::class, $kernel);

        (new LoadMigrationsFromArray(false, false))->bootstrap($this->app);

        $kernel->shouldNotReceive('call');

        app('events')->dispatch(new DatabaseRefreshed);
    }
}
