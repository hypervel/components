<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Migrations\DatabaseMigrationRepository;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class DatabaseMigratorIntegrationTest extends TestCase
{
    protected Migrator $migrator;

    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', 'sqlite');

        $app->make('config')->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app->make('config')->set('database.connections.sqlite2', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app->make('config')->set('database.connections.sqlite3', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app->make('config')->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $databaseManager = $this->app->make('db');

        $this->migrator = new Migrator(
            $repository = new DatabaseMigrationRepository($databaseManager, 'migrations'),
            $databaseManager,
            new Filesystem
        );

        $output = m::mock(OutputStyle::class);
        $output->shouldReceive('write');
        $output->shouldReceive('writeln');
        $output->shouldReceive('newLinesWritten');

        $this->migrator->setOutput($output);

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $repository2 = new DatabaseMigrationRepository($databaseManager, 'migrations');
        $repository2->setSource('sqlite2');

        if (! $repository2->repositoryExists()) {
            $repository2->createRepository();
        }

        $repository3 = new DatabaseMigrationRepository($databaseManager, 'migrations');
        $repository3->setSource('default');

        if (! $repository3->repositoryExists()) {
            $repository3->createRepository();
        }
    }

    public function testBasicMigrationOfSingleFolder(): void
    {
        $ran = $this->migrator->run([__DIR__ . '/Fixtures/migrations/one']);

        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));

        $this->assertStringContainsString('users', $ran[0]);
        $this->assertStringContainsString('password_resets', $ran[1]);
    }

    public function testMigrationsDefaultConnectionCanBeChanged(): void
    {
        $ran = $this->migrator->usingConnection('sqlite2', function () {
            return $this->migrator->run([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqllite3']);
        });

        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->assertTrue(DB::connection('sqlite2')->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection('sqlite2')->getSchemaBuilder()->hasTable('password_resets'));
        $this->assertFalse(DB::connection('sqlite3')->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection('sqlite3')->getSchemaBuilder()->hasTable('password_resets'));

        $this->assertTrue(Str::contains($ran[0], 'users'));
        $this->assertTrue(Str::contains($ran[1], 'password_resets'));
    }

    public function testMigrationsCanEachDefineConnection(): void
    {
        $ran = $this->migrator->run([__DIR__ . '/Fixtures/migrations/connection_configured']);

        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('failed_jobs'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('jobs'));
        $this->assertFalse(DB::connection('sqlite2')->getSchemaBuilder()->hasTable('failed_jobs'));
        $this->assertFalse(DB::connection('sqlite2')->getSchemaBuilder()->hasTable('jobs'));
        $this->assertTrue(DB::connection('sqlite3')->getSchemaBuilder()->hasTable('failed_jobs'));
        $this->assertTrue(DB::connection('sqlite3')->getSchemaBuilder()->hasTable('jobs'));

        $this->assertTrue(Str::contains($ran[0], 'failed_jobs'));
        $this->assertTrue(Str::contains($ran[1], 'jobs'));
    }

    public function testMigratorCannotChangeDefinedMigrationConnection(): void
    {
        $ran = $this->migrator->usingConnection('sqlite2', function () {
            return $this->migrator->run([__DIR__ . '/Fixtures/migrations/connection_configured']);
        });

        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('failed_jobs'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('jobs'));
        $this->assertFalse(DB::connection('sqlite2')->getSchemaBuilder()->hasTable('failed_jobs'));
        $this->assertFalse(DB::connection('sqlite2')->getSchemaBuilder()->hasTable('jobs'));
        $this->assertTrue(DB::connection('sqlite3')->getSchemaBuilder()->hasTable('failed_jobs'));
        $this->assertTrue(DB::connection('sqlite3')->getSchemaBuilder()->hasTable('jobs'));

        $this->assertTrue(Str::contains($ran[0], 'failed_jobs'));
        $this->assertTrue(Str::contains($ran[1], 'jobs'));
    }

    public function testMigrationsCanBeRolledBack(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $rolledBack = $this->migrator->rollback([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));

        $this->assertStringContainsString('password_resets', $rolledBack[0]);
        $this->assertStringContainsString('users', $rolledBack[1]);
    }

    public function testMigrationsCanBeResetUsingAnString(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $rolledBack = $this->migrator->reset(__DIR__ . '/Fixtures/migrations/one');
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));

        $this->assertStringContainsString('password_resets', $rolledBack[0]);
        $this->assertStringContainsString('users', $rolledBack[1]);
    }

    public function testMigrationsCanBeResetUsingAnArray(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $rolledBack = $this->migrator->reset([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));

        $this->assertStringContainsString('password_resets', $rolledBack[0]);
        $this->assertStringContainsString('users', $rolledBack[1]);
    }

    public function testNoErrorIsThrownWhenNoOutstandingMigrationsExist(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one']);
    }

    public function testNoErrorIsThrownWhenNothingToRollback(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->migrator->rollback([__DIR__ . '/Fixtures/migrations/one']);
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->migrator->rollback([__DIR__ . '/Fixtures/migrations/one']);
    }

    public function testMigrationsCanRunAcrossMultiplePaths(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one', __DIR__ . '/Fixtures/migrations/two']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('flights'));
    }

    public function testMigrationsCanBeRolledBackAcrossMultiplePaths(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one', __DIR__ . '/Fixtures/migrations/two']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('flights'));
        $this->migrator->rollback([__DIR__ . '/Fixtures/migrations/one', __DIR__ . '/Fixtures/migrations/two']);
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('flights'));
    }

    public function testMigrationsCanBeResetAcrossMultiplePaths(): void
    {
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one', __DIR__ . '/Fixtures/migrations/two']);
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('flights'));
        $this->migrator->reset([__DIR__ . '/Fixtures/migrations/one', __DIR__ . '/Fixtures/migrations/two']);
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('password_resets'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('flights'));
    }

    public function testMigrationsCanBeProperlySortedAcrossMultiplePaths(): void
    {
        $paths = [__DIR__ . '/Fixtures/migrations/multi_path/vendor', __DIR__ . '/Fixtures/migrations/multi_path/app'];

        $migrationsFilesFullPaths = array_values($this->migrator->getMigrationFiles($paths));

        $expected = [
            __DIR__ . '/Fixtures/migrations/multi_path/app/2016_01_01_000000_create_users_table.php', // This file was not created on the "vendor" directory on purpose
            __DIR__ . '/Fixtures/migrations/multi_path/vendor/2016_01_01_200000_create_flights_table.php', // This file was not created on the "app" directory on purpose
            __DIR__ . '/Fixtures/migrations/multi_path/app/2019_08_08_000001_rename_table_one.php',
            __DIR__ . '/Fixtures/migrations/multi_path/app/2019_08_08_000002_rename_table_two.php',
            __DIR__ . '/Fixtures/migrations/multi_path/app/2019_08_08_000003_rename_table_three.php',
            __DIR__ . '/Fixtures/migrations/multi_path/app/2019_08_08_000004_rename_table_four.php',
            __DIR__ . '/Fixtures/migrations/multi_path/app/2019_08_08_000005_create_table_one.php',
            __DIR__ . '/Fixtures/migrations/multi_path/app/2019_08_08_000006_create_table_two.php',
            __DIR__ . '/Fixtures/migrations/multi_path/vendor/2019_08_08_000007_create_table_three.php', // This file was not created on the "app" directory on purpose
            __DIR__ . '/Fixtures/migrations/multi_path/app/2019_08_08_000008_create_table_four.php',
        ];

        $this->assertEquals($expected, $migrationsFilesFullPaths);
    }

    public function testConnectionPriorToMigrationIsNotChangedAfterMigration(): void
    {
        $this->migrator->setConnection('default');
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->assertSame('default', $this->migrator->getConnection());
    }

    public function testConnectionPriorToMigrationIsNotChangedAfterRollback(): void
    {
        $this->migrator->setConnection('default');
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->migrator->rollback([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->assertSame('default', $this->migrator->getConnection());
    }

    public function testConnectionPriorToMigrationIsNotChangedWhenNoOutstandingMigrationsExist(): void
    {
        $this->migrator->setConnection('default');
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->migrator->setConnection('default');
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->assertSame('default', $this->migrator->getConnection());
    }

    public function testConnectionPriorToMigrationIsNotChangedWhenNothingToRollback(): void
    {
        $this->migrator->setConnection('default');
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->migrator->rollback([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->migrator->rollback([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->assertSame('default', $this->migrator->getConnection());
    }

    public function testConnectionPriorToMigrationIsNotChangedAfterMigrateReset(): void
    {
        $this->migrator->setConnection('default');
        $this->migrator->run([__DIR__ . '/Fixtures/migrations/one'], ['database' => 'sqlite2']);
        $this->migrator->usingConnection('sqlite2', function () {
            $this->migrator->reset([__DIR__ . '/Fixtures/migrations/one']);
        });
        $this->assertSame('default', $this->migrator->getConnection());
    }
}
