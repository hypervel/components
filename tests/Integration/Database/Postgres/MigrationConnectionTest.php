<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Database\Connection;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class MigrationConnectionTest extends PostgresTestCase
{
    #[DataProvider('connectionModes')]
    public function testMigrationUsesItsResolvedConnectionForTheTransaction(bool $pooled, bool $customResolver): void
    {
        $configuration = DB::connection()->getConfig();
        unset($configuration['name']);
        $configuration['pool']['testing_enabled'] = $pooled;
        config(['database.connections.migration_role' => $configuration]);
        config(['database.connections.migration_alternate' => $configuration]);

        $connection = DB::connection($customResolver ? 'migration_alternate::write' : 'migration_role::write');

        if ($customResolver) {
            Migrator::resolveConnectionsUsing(static fn (): Connection => $connection);
        }

        $failure = new RuntimeException('Migration failed after creating its table.');
        $migration = new class($failure) extends Migration {
            protected ?string $connection = 'migration_role::write';

            public ?Connection $resolvedConnection = null;

            /**
             * Create a migration that fails after executing DDL.
             */
            public function __construct(private RuntimeException $failure)
            {
            }

            /**
             * Create a table before failing the migration.
             */
            public function up(): void
            {
                $this->resolvedConnection = DB::connection();
                Schema::create('migration_role_rollback', static function (Blueprint $table): void {
                    $table->integer('value');
                });

                throw $this->failure;
            }
        };

        $migrator = new MigrationConnectionMigrator(
            $this->app->make('migration.repository'),
            $this->app->make('db'),
            new Filesystem,
        );
        $defaultConnection = DB::getDefaultConnection();

        try {
            try {
                $migrator->runMigrationForTest($migration);
                $this->fail('Expected the migration to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception, $exception->getMessage());
            }

            $this->assertFalse($connection->getSchemaBuilder()->hasTable('migration_role_rollback'));
            $this->assertSame($connection, $migration->resolvedConnection);
            $this->assertSame($defaultConnection, DB::getDefaultConnection());

            if (! $pooled) {
                DatabaseConnectionResolver::resetCachedConnections();

                $name = $customResolver ? 'migration_alternate::write' : 'migration_role::write';
                $this->assertSame($connection, DB::connection($name));
                $this->assertSame($name, $connection->getNameWithReadWriteType());
            }
        } finally {
            $connection->getSchemaBuilder()->dropIfExists('migration_role_rollback');
        }
    }

    /**
     * Get runtime and Testbench connection resolution modes.
     */
    public static function connectionModes(): array
    {
        return [
            'pooled alias' => [true, false],
            'pooled custom resolver' => [true, true],
            'cached alias' => [false, false],
            'cached custom resolver' => [false, true],
        ];
    }
}

class MigrationConnectionMigrator extends Migrator
{
    /**
     * Run the migration through its transaction boundary.
     */
    public function runMigrationForTest(Migration $migration): void
    {
        $this->runMigration($migration, 'up');
    }
}
