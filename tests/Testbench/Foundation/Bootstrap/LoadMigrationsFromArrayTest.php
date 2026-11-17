<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Workbench\Database\Seeders\TestbenchDatabaseSeeder;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\workbench_path;

class LoadMigrationsFromArrayTest extends TestCase
{
    use VerifiesDoubles;

    #[Test]
    public function itCanRegisterMigrations(): void
    {
        $this->instance('migrator', $migrator = Double::for(Migrator::class)->strict());

        $paths = [workbench_path('database/migrations')];

        $migrator->expects('path')->with($paths[0]);
        $migrator->expects('path')->with(default_migration_path());

        (new LoadMigrationsFromArray($paths))->bootstrap($this->app);
    }

    #[Test]
    public function itCanSkipMigrationsRegistration(): void
    {
        $this->instance('migrator', $migrator = Double::for(Migrator::class)->strict());

        $migrator->expects('path')->never();

        (new LoadMigrationsFromArray(false))->bootstrap($this->app);
    }

    #[Test]
    public function itCanSeedDatabaseAfterRefreshed(): void
    {
        (new LoadMigrationsFromArray(false, [
            'seeders' => [TestbenchDatabaseSeeder::class],
        ]))->bootstrap($this->app);

        $this->instance(TestbenchDatabaseSeeder::class, $seeder = Double::for(TestbenchDatabaseSeeder::class)->strict());

        $seeder->expects('setContainer')->with($this->app)->returns($seeder);
        $seeder->expects('setCommand')->returns($seeder);
        $seeder->expects('__invoke');

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itCanRunTheDefaultSeederAfterRefreshed(): void
    {
        $kernel = Double::for(ConsoleKernel::class)->strict();
        $this->app->instance(ConsoleKernel::class, $kernel);
        (new LoadMigrationsFromArray(false, true))->bootstrap($this->app);
        $kernel->expects('call')->with('db:seed')->returns(0);

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itRejectsMissingSeederClasses(): void
    {
        (new LoadMigrationsFromArray(false, 'MissingSeeder'))->bootstrap($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Seeder class [MissingSeeder] does not exist.');

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itRejectsNonStringSeederConfiguration(): void
    {
        (new LoadMigrationsFromArray(false, [123]))->bootstrap($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Testbench seeders must be existing class strings.');

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itFailsWhenTheDefaultSeederCommandFails(): void
    {
        $kernel = Double::for(ConsoleKernel::class)->strict();
        $this->app->instance(ConsoleKernel::class, $kernel);
        (new LoadMigrationsFromArray(false, true))->bootstrap($this->app);
        $kernel->expects('call')->with('db:seed')->returns(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Default seeder failed.');

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itFailsWhenAnExplicitSeederCommandFails(): void
    {
        $kernel = Double::for(ConsoleKernel::class)->strict();
        $this->app->instance(ConsoleKernel::class, $kernel);
        (new LoadMigrationsFromArray(false, TestbenchDatabaseSeeder::class))->bootstrap($this->app);
        $kernel->expects('call')->with('db:seed', [
            '--class' => TestbenchDatabaseSeeder::class,
        ])->returns(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(sprintf('Seeder [%s] failed.', TestbenchDatabaseSeeder::class));

        app('events')->dispatch(new DatabaseRefreshed);
    }

    #[Test]
    public function itCanSkipDatabaseSeedingAfterRefreshed(): void
    {
        $kernel = Double::for(ConsoleKernel::class)->strict();
        $this->app->instance(ConsoleKernel::class, $kernel);

        (new LoadMigrationsFromArray(false, false))->bootstrap($this->app);

        $kernel->expects('call')->never();

        app('events')->dispatch(new DatabaseRefreshed);
    }
}
