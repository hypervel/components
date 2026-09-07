<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Workbench\Database\Seeders\TestbenchDatabaseSeeder;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\workbench_path;

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
    public function itCanRunTheDefaultSeederAfterRefreshed(): void
    {
        $kernel = m::mock(ConsoleKernel::class);
        $this->app->instance(ConsoleKernel::class, $kernel);
        (new LoadMigrationsFromArray(false, true))->bootstrap($this->app);
        $kernel->expects('call')->with('db:seed')->andReturn(0);

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itRejectsMissingSeederClasses(): void
    {
        (new LoadMigrationsFromArray(false, 'MissingSeeder'))->bootstrap($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seeder class [MissingSeeder] does not exist.');

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itRejectsNonStringSeederConfiguration(): void
    {
        (new LoadMigrationsFromArray(false, [123]))->bootstrap($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Testbench seeders must be existing class strings.');

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itFailsWhenTheDefaultSeederCommandFails(): void
    {
        $kernel = m::mock(ConsoleKernel::class);
        $this->app->instance(ConsoleKernel::class, $kernel);
        (new LoadMigrationsFromArray(false, true))->bootstrap($this->app);
        $kernel->expects('call')->with('db:seed')->andReturn(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Default seeder failed.');

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itFailsWhenAnExplicitSeederCommandFails(): void
    {
        $kernel = m::mock(ConsoleKernel::class);
        $this->app->instance(ConsoleKernel::class, $kernel);
        (new LoadMigrationsFromArray(false, TestbenchDatabaseSeeder::class))->bootstrap($this->app);
        $kernel->expects('call')->with('db:seed', [
            '--class' => TestbenchDatabaseSeeder::class,
        ])->andReturn(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Seeder [%s] failed.', TestbenchDatabaseSeeder::class));

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
