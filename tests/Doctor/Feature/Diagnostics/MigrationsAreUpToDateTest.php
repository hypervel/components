<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Doctor\Diagnostics\MigrationsAreUpToDate;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

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
        $root = ParallelTesting::tempDir('DoctorPendingMigrations');
        File::deleteDirectory($root);
        File::makeDirectory($root . '/migrations', 0775, true);

        try {
            $this->app->useDatabasePath($root);
            touch($root . '/migrations/2024_01_01_000000_create_users_table.php');
            $migrator = $this->app->make('migrator');
            $migrator->usingConnection(null, fn () => $migrator->getRepository()->createRepository());

            $result = (new MigrationsAreUpToDate)->check();

            $this->assertSame('fail', $result->status->value);
            $this->assertStringContainsString('2024_01_01_000000_create_users_table', $result->details);
            $this->assertSame('Apply pending migrations using `php artisan migrate`?', $result->confirmation);
            $this->assertSame('Run `php artisan migrate` to apply the pending migrations.', $result->remediation);
            $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
            $this->assertTrue($result->fixableIn(EnvironmentMode::Local));
            $this->assertFalse($result->fixableIn(EnvironmentMode::Production));
            $this->assertEquals([Link::docs('migrations', 'running-migrations')], $result->links);
        } finally {
            File::deleteDirectory($root);
        }
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
        $root = ParallelTesting::tempDir('DoctorMigrationRepair');
        File::deleteDirectory($root);
        File::makeDirectory($root . '/migrations', 0775, true);
        File::makeDirectory($root . '/package', 0775, true);

        try {
            $this->app->useDatabasePath($root);
            $this->app->make('migrator')->path($root . '/package');
            File::put($root . '/package/2024_01_01_000000_create_doctor_entries_table.php', <<<'PHP'
                <?php

                use Hypervel\Database\Migrations\Migration;
                use Hypervel\Database\Schema\Blueprint;
                use Hypervel\Support\Facades\Schema;

                return new class extends Migration {
                    public function up(): void
                    {
                        Schema::create('doctor_entries', function (Blueprint $table): void {
                            $table->id();
                        });
                    }
                };
                PHP);

            $diagnostic = new MigrationsAreUpToDate;
            $fix = $diagnostic->fix($diagnostic->check());

            $this->assertSame('pass', $fix->status->value);
            $this->assertSame('migrations-are-up-to-date.fix.migrated', $fix->code);
            $this->assertSame('Database migrations were applied.', $fix->summary);
            $this->assertTrue(DB::connection('schema')->getSchemaBuilder()->hasTable('doctor_entries'));
            $this->assertFalse(DB::connection('primary')->getSchemaBuilder()->hasTable('doctor_entries'));
            $this->assertSame('pass', $diagnostic->check()->status->value);
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[DataProvider('migrationFailures')]
    public function testReportsWhenPendingMigrationsCannotBeApplied(?string $exceptionClass): void
    {
        $kernel = m::mock(Kernel::class);
        Artisan::swap($kernel);
        $command = $kernel->shouldReceive('call')->once()->with('migrate', ['--no-interaction' => true]);

        if ($exceptionClass !== null) {
            $exception = new $exceptionClass('Database connection refused.');
            $command->andThrow($exception);

            if ($exception instanceof CanceledException) {
                $this->expectExceptionObject($exception);
            }
        } else {
            $command->andReturn(1);
            $kernel->shouldReceive('output')->once()->andReturn('Database connection refused.');
        }

        $fix = (new MigrationsAreUpToDate)->fix(DiagnosticResult::fail('pending'));

        $this->assertSame('fail', $fix->status->value);
        $this->assertSame('migrations-are-up-to-date.fix.migration-failed', $fix->code);
        $this->assertSame('Database migrations could not be applied.', $fix->summary);
        $this->assertStringContainsString('Database connection refused.', $fix->details);
    }

    /**
     * Provide command failure and cancellation paths.
     */
    public static function migrationFailures(): array
    {
        return [
            'nonzero exit' => [null],
            'exception' => [RuntimeException::class],
            'cancellation' => [CanceledException::class],
        ];
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
            touch($root . '/package/2024_01_01_000003_excluded.php');
            touch($root . '/package/2024_01_01_000004_excluded_by_filename.php');
            Migrator::withoutMigrations([
                '2024_01_01_000003_excluded',
                '2024_01_01_000004_excluded_by_filename.php',
            ]);
            $migrator->setConnection('prior');
            DB::setDefaultConnection('primary');

            $diagnostic = new MigrationsAreUpToDate;
            $this->assertSame('migrations-are-up-to-date.current', $diagnostic->check()->code);
            touch($root . '/package/2024_01_01_000002_pending.php');
            $details = $diagnostic->check()->details;
            $this->assertStringContainsString('2024_01_01_000002_pending', $details);
            $this->assertStringNotContainsString('excluded', $details);
            $this->assertSame('primary', DB::getDefaultConnection());
            $this->assertSame('prior', $migrator->getConnection());
            $this->assertSame('prior', $repository->getConnection()->getName());
        } finally {
            File::deleteDirectory($root);
        }
    }
}
