<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\MigrationsAreUpToDate;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

class MigrationsAreUpToDateTest extends TestCase
{
    /**
     * Configure separate application and migration connections.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', 'primary');
        $app->make('config')->set('database.connections', [
            'primary' => ['driver' => 'sqlite', 'database' => ':memory:', 'migrations_connection' => 'schema'],
            'schema' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'prior' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
    }

    public function testReportsPendingMigrations(): void
    {
        $this->app->instance('migrator', new class {
            /**
             * Get the available migration files.
             */
            public function getMigrationFiles(array $paths): array
            {
                return ['2024_01_01_000000_create_users_table' => $paths[0] . '/2024_01_01_000000_create_users_table.php'];
            }

            /**
             * Determine whether the migration repository exists.
             */
            public function repositoryExists(): bool
            {
                return true;
            }

            /**
             * Get the migration repository.
             */
            public function getRepository(): object
            {
                return new class {
                    /**
                     * Get the completed migrations.
                     */
                    public function getRan(): array
                    {
                        return [];
                    }
                };
            }

            /**
             * Get the registered migration paths.
             */
            public function paths(): array
            {
                return [];
            }

            /**
             * Run the callback on the selected connection.
             */
            public function usingConnection(?string $name, callable $callback): mixed
            {
                return $callback();
            }
        });

        $result = (new MigrationsAreUpToDate)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('2024_01_01_000000_create_users_table', $result->details);
        $this->assertSame('Apply pending migrations using `php artisan migrate`?', $result->confirmation);
        $this->assertSame('Run `php artisan migrate` to apply the pending migrations.', $result->remediation);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
        $this->assertTrue($result->fixableIn(EnvironmentMode::Local));
        $this->assertFalse($result->fixableIn(EnvironmentMode::Production));
        $this->assertEquals([Link::docs('migrations', 'running-migrations')], $result->links);
    }

    public function testOffersToCreateTheMigrationRepositoryLocally(): void
    {
        $this->app->instance('migrator', new class {
            /**
             * Get the available migration files.
             */
            public function getMigrationFiles(array $paths): array
            {
                return ['2024_01_01_000000_create_users_table' => $paths[0] . '/2024_01_01_000000_create_users_table.php'];
            }

            /**
             * Determine whether the migration repository exists.
             */
            public function repositoryExists(): bool
            {
                return false;
            }

            /**
             * Get the registered migration paths.
             */
            public function paths(): array
            {
                return [];
            }

            /**
             * Run the callback on the selected connection.
             */
            public function usingConnection(?string $name, callable $callback): mixed
            {
                return $callback();
            }
        });

        $result = (new MigrationsAreUpToDate)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Create the migrations table and apply pending migrations using `php artisan migrate`?', $result->confirmation);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
    }

    public function testAppliesPendingMigrationsWhenFixed(): void
    {
        Process::fake([
            '*' => Process::result(output: 'Migrating: 2024_01_01_000000_create_users_table'),
        ]);

        $fix = (new MigrationsAreUpToDate)->fix(DiagnosticResult::fail('pending'));

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            PHP_BINARY,
            'artisan',
            'migrate',
            '--no-interaction',
        ] && $process->path === base_path());

        $this->assertSame('pass', $fix->status->value);
        $this->assertSame('migrations-are-up-to-date.fix.migrated', $fix->code);
        $this->assertSame('Database migrations were applied.', $fix->summary);
    }

    public function testReportsWhenPendingMigrationsCannotBeApplied(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'Database connection refused.', exitCode: 1),
        ]);

        $fix = (new MigrationsAreUpToDate)->fix(DiagnosticResult::fail('pending'));

        $this->assertSame('fail', $fix->status->value);
        $this->assertSame('migrations-are-up-to-date.fix.migration-failed', $fix->code);
        $this->assertSame('Database migrations could not be applied.', $fix->summary);
        $this->assertStringContainsString('Database connection refused.', $fix->details);
    }

    public function testUsesMigrationConnectionAndRegisteredPathsWithoutChangingAmbientState(): void
    {
        $root = ParallelTesting::tempDir('DoctorMigrations');
        File::deleteDirectory($root);
        File::makeDirectory($root . '/migrations', 0775, true);
        File::makeDirectory($root . '/package', 0775, true);

        try {
            $this->app->useDatabasePath($root);
            touch($root . '/migrations/2024_01_01_000000_application.php');
            touch($root . '/package/2024_01_01_000001_package.php');
            $migrator = $this->app->make('migrator');
            $migrator->path($root . '/package');
            $repository = $migrator->getRepository();
            $repository->setSource('schema');
            $repository->createRepository();
            $repository->log('2024_01_01_000000_application', 1);
            $repository->log('2024_01_01_000001_package', 1);
            $migrator->setConnection('prior');
            DB::setDefaultConnection('primary');

            $diagnostic = new MigrationsAreUpToDate;
            $this->assertSame('migrations-are-up-to-date.current', $diagnostic->check()->code);
            touch($root . '/package/2024_01_01_000002_pending.php');
            $this->assertStringContainsString('2024_01_01_000002_pending', $diagnostic->check()->details);
            $this->assertSame('primary', DB::getDefaultConnection());
            $this->assertSame('prior', $migrator->getConnection());
            $this->assertSame('prior', $repository->getConnection()->getName());
        } finally {
            File::deleteDirectory($root);
        }
    }
}
