<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Database\Migrations\Migrator;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Database\MigrateProcessor;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function Hypervel\Testbench\after_resolving;
use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\join_paths;

#[WithConfig('database.default', 'testing')]
class MigrateWithHypervelTest extends TestCase
{
    #[Test]
    #[DefineDatabase('loadApplicationMigrations')]
    public function itLoadsTheMigrations(): void
    {
        $now = CarbonImmutable::now();

        DB::table('users')->insert([
            'name' => 'Orchestra',
            'email' => 'crynobone@gmail.com',
            'password' => Hash::make('456'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $users = DB::table('users')->where('id', '=', 1)->first();

        $this->assertEquals('crynobone@gmail.com', $users->email);
        $this->assertTrue(Hash::check('456', $users->password));
    }

    #[Test]
    #[DefineDatabase('runApplicationMigrations')]
    public function itRunsTheMigrations(): void
    {
        $now = CarbonImmutable::now();

        DB::table('users')->insert([
            'name' => 'Orchestra',
            'email' => 'crynobone@gmail.com',
            'password' => Hash::make('456'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $users = DB::table('users')->where('id', '=', 1)->first();

        $this->assertEquals('crynobone@gmail.com', $users->email);
        $this->assertTrue(Hash::check('456', $users->password));
    }

    #[Test]
    public function itOwnsAndCleansMigrationBatchesOnASecondaryConnection(): void
    {
        $this->app->make('config')->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);
        $this->loadHypervelMigrations('secondary');
        $migrator = $this->app->make(Migrator::class);
        $repository = $migrator->getRepository();

        $migrator->usingConnection('secondary', function () use ($repository): void {
            $this->assertNotSame([], $repository->getMigrationBatches());
        });

        $this->tearDownInteractsWithMigrations();

        $migrator->usingConnection('secondary', function () use ($repository): void {
            $this->assertSame([], $repository->getMigrationBatches());
        });
    }

    #[Test]
    public function itDetachesProcessorsAndContinuesMigrationCleanupAfterFailure(): void
    {
        $failure = new RuntimeException('First rollback failed.');
        $first = m::mock(MigrateProcessor::class);
        $first->shouldReceive('rollback')->once()->andThrow($failure);
        $second = m::mock(MigrateProcessor::class);
        $second->shouldReceive('rollback')->once()->andReturnSelf();
        $this->cachedTestMigratorProcessors = [$first, $second];

        try {
            $this->tearDownInteractsWithMigrations();
            $this->fail('Expected migration cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([], $this->cachedTestMigratorProcessors);

        $this->tearDownInteractsWithMigrations();
    }

    #[Test]
    public function itCompensatesCompletedMigrationsWhenAFollowingMigrationFails(): void
    {
        $filesystem = new Filesystem;
        $migrationPath = base_path('migrations/failing-testbench-processor');
        $filesystem->ensureDirectoryExists($migrationPath);
        $filesystem->put(
            join_paths($migrationPath, '2026_08_09_000000_create_processor_owned_table.php'),
            <<<'PHP'
<?php

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processor_owned_table', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processor_owned_table');
    }
};
PHP,
        );
        $filesystem->put(
            join_paths($migrationPath, '2026_08_09_000001_fail_processor_migration.php'),
            <<<'PHP'
<?php

use Hypervel\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('Planned migration failure.');
    }

    public function down(): void
    {
    }
};
PHP,
        );

        try {
            try {
                $this->loadMigrationsFrom($migrationPath);
                $this->fail('Expected the second migration to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Planned migration failure.', $exception->getMessage());
            }

            $this->assertFalse(Schema::hasTable('processor_owned_table'));
        } finally {
            $filesystem->deleteDirectory($migrationPath);
        }
    }

    public function loadApplicationMigrations(): void
    {
        $this->loadHypervelMigrations(['--database' => 'testing']);
    }

    public function runApplicationMigrations(): void
    {
        after_resolving($this->app, 'migrator', function ($migrator): void {
            $migrator->path(default_migration_path());
        });

        $this->runHypervelMigrations(['--database' => 'testing']);
    }
}
