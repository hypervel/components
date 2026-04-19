<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\TestCase;

/**
 * End-to-end proof that the "migrations_connection" config key routes migration
 * SQL, the migration repository, and the Migrator's resolver through the swapped
 * connection — not just the execution path.
 *
 * The two SQLite connections point at DIFFERENT files so we can assert which
 * database the migrations table actually landed in. If the migrations table
 * ever shows up in "primary", routing is broken.
 */
class MigrationsConnectionRoutingTest extends TestCase
{
    protected string $primaryPath;

    protected string $migrationsPath;

    protected string $otherPath;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        // Paths resolve inside testbench's disposable cloned workspace so the
        // committed source tree is never written to. Testbench sweeps the
        // whole workspace on shutdown.
        $this->primaryPath = $app->databasePath('primary.sqlite');
        $this->migrationsPath = $app->databasePath('primary-migrations.sqlite');
        $this->otherPath = $app->databasePath('other.sqlite');

        // SQLite requires the file to exist — Hypervel (like Laravel) refuses
        // to auto-create missing database files. touch() creates an empty
        // file, which SQLite happily treats as a fresh, empty database.
        touch($this->primaryPath);
        touch($this->migrationsPath);
        touch($this->otherPath);

        $config = $app->make('config');

        $config->set('database.default', 'primary');

        $config->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->primaryPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'migrations_connection' => 'primary-migrations',
        ]);

        $config->set('database.connections.primary-migrations', [
            'driver' => 'sqlite',
            'database' => $this->migrationsPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        // Third connection used only by the scoped-override test — never
        // the routing target for anyone; provides a third SQLite file so
        // we can prove routing didn't silently fall back to the configured
        // default.
        $config->set('database.connections.other', [
            'driver' => 'sqlite',
            'database' => $this->otherPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function tearDown(): void
    {
        /** @var DatabaseManager $db */
        $db = $this->app->make('db');
        $db->purge('primary');
        $db->purge('primary-migrations');
        $db->purge('other');

        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        File::delete($this->primaryPath);
        File::delete($this->migrationsPath);
        File::delete($this->otherPath);

        parent::tearDown();
    }

    public function testMigrationRepositoryLandsOnMigrationsConnection(): void
    {
        // setConnection() swaps the name via resolveMigrationConnectionName()
        // and propagates the swapped name to the shared repository singleton
        // via $repository->setSource(). After that, any call on the repository
        // (like createRepository) targets the migrations_connection sibling.
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');
        $migrator->setConnection('primary');

        $this->assertSame('primary-migrations', $migrator->getConnection());

        $repository = $this->app->make('migration.repository');
        $repository->createRepository();

        /** @var DatabaseManager $db */
        $db = $this->app->make('db');

        $this->assertTrue(
            $db->connection('primary-migrations')->getSchemaBuilder()->hasTable('migrations'),
            'Migrations table must live on the primary-migrations connection',
        );

        $this->assertFalse(
            $db->connection('primary')->getSchemaBuilder()->hasTable('migrations'),
            'Migrations table must NOT live on the pooled primary connection',
        );
    }

    public function testResolveConnectionRoutesToMigrationsConnection(): void
    {
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');

        $connection = $migrator->resolveConnection('primary');

        $this->assertSame(
            'primary-migrations',
            $connection->getName(),
            'resolveConnection("primary") must return the primary-migrations connection',
        );
    }

    public function testMigrationSqlExecutesOnMigrationsConnection(): void
    {
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');

        // Resolve the migration connection and create a real table through it.
        $connection = $migrator->resolveConnection('primary');
        $connection->getSchemaBuilder()->create('probe', function (Blueprint $table) {
            $table->increments('id');
        });

        /** @var DatabaseManager $db */
        $db = $this->app->make('db');

        $this->assertTrue(
            $db->connection('primary-migrations')->getSchemaBuilder()->hasTable('probe'),
            'Schema change should land on primary-migrations',
        );

        $this->assertFalse(
            $db->connection('primary')->getSchemaBuilder()->hasTable('probe'),
            'Schema change must not land on the primary (pooled) connection',
        );
    }

    public function testUsingConnectionWithNullRoutesViaConfiguredDefault(): void
    {
        // End-to-end equivalent of `php artisan migrate` with no --database:
        // the app default 'primary' has migrations_connection => 'primary-migrations',
        // so the Migrator must route the null-name call through the default
        // to the direct sibling. A schema change performed inside the
        // usingConnection(null) block should land on primary-migrations.
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');

        $migrator->usingConnection(null, function () use ($migrator) {
            $migrator->resolveConnection(null)
                ->getSchemaBuilder()
                ->create('default_probe', function (Blueprint $table) {
                    $table->increments('id');
                });
        });

        /** @var DatabaseManager $db */
        $db = $this->app->make('db');

        $this->assertTrue(
            $db->connection('primary-migrations')->getSchemaBuilder()->hasTable('default_probe'),
            'null-routed schema change should land on primary-migrations',
        );

        $this->assertFalse(
            $db->connection('primary')->getSchemaBuilder()->hasTable('default_probe'),
            'null-routed schema change must not land on the pooled primary',
        );
    }

    public function testScopedDefaultOverrideRoutesMigrationsToContextSibling(): void
    {
        // End-to-end regression for the real-world scenario GPT flagged:
        //
        //     DB::usingConnection('primary', fn () => run migrations);
        //
        // config.default points at an UNRELATED connection ('other'). The
        // outer scope sets a Context override to 'primary'. A migration
        // operation inside that scope with no explicit --database must route
        // via the Context override's migrations_connection ('primary-migrations'),
        // NOT via the configured default ('other').
        //
        // If routing is wrong, the probe table will land on 'other' or
        // 'primary' — both failures prove the regression is back.
        /** @var DatabaseManager $db */
        $db = $this->app->make('db');

        // Temporarily flip the configured default away from 'primary' so we
        // can tell Context-vs-config apart. Restored manually below because
        // DatabaseManager::setDefaultConnection writes to Context, not config.
        $this->app->make('config')->set('database.default', 'other');

        // Simulate the outer DB::usingConnection('primary', ...) scope.
        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'primary');

        try {
            /** @var Migrator $migrator */
            $migrator = $this->app->make('migrator');

            $migrator->usingConnection(null, function () use ($migrator) {
                $migrator->resolveConnection(null)
                    ->getSchemaBuilder()
                    ->create('scoped_probe', function (Blueprint $table) {
                        $table->increments('id');
                    });
            });

            $this->assertTrue(
                $db->connection('primary-migrations')->getSchemaBuilder()->hasTable('scoped_probe'),
                'Schema change must land on primary-migrations (Context target\'s sibling)',
            );

            $this->assertFalse(
                $db->connection('primary')->getSchemaBuilder()->hasTable('scoped_probe'),
                'Schema change must NOT land on the pooled Context target',
            );

            $this->assertFalse(
                $db->connection('other')->getSchemaBuilder()->hasTable('scoped_probe'),
                'Schema change must NOT land on the configured default — '
                . 'Context must override config for effective-default resolution',
            );

            $this->assertSame(
                'primary',
                CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
                'Outer scope\'s Context override must be restored after the inner usingConnection',
            );
        } finally {
            $this->app->make('config')->set('database.default', 'primary');
        }
    }
}
