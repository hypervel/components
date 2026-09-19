<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('pdo_pgsql')]
class DatabasePgsqlPooledConnectionTest extends PostgresTestCase
{
    /**
     * Configure native prepares for the migration connection.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.connections.pgsql.options', [
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Configure the pooler connection against the isolated test database.
     */
    protected function afterRefreshingDatabase(): void
    {
        $config = DB::connection('pgsql')->getConfig();
        unset($config['name']);
        $config['options'][PDO::ATTR_EMULATE_PREPARES] = true;
        $config['migrations_connection'] = 'pgsql';
        $config['pool']['testing_enabled'] = true;

        config(['database.connections.pgsql-pooled' => $config]);
    }

    public function testPooledAndDirectConnectionsUseExpectedPrepareModes(): void
    {
        $this->assertTrue(
            DB::connection('pgsql-pooled')->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES)
        );

        $this->assertFalse(
            DB::connection('pgsql')->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES)
        );
    }

    public function testRuntimeSchemaInspectionWorksThroughPooledConnection(): void
    {
        $this->assertIsBool(DB::connection('pgsql-pooled')->getSchemaBuilder()->hasTable('migrations'));
    }

    public function testPooledConnectionCanBindBooleansWithEmulatedPrepares(): void
    {
        $schema = DB::connection('pgsql')->getSchemaBuilder();

        $schema->dropIfExists('pooled_boolean_bindings');
        $schema->create('pooled_boolean_bindings', function (Blueprint $table): void {
            $table->boolean('active');
        });

        try {
            DB::connection('pgsql-pooled')->table('pooled_boolean_bindings')->insert([
                'active' => true,
            ]);

            $this->assertSame(
                1,
                DB::connection('pgsql-pooled')->table('pooled_boolean_bindings')->where('active', true)->count()
            );
        } finally {
            $schema->dropIfExists('pooled_boolean_bindings');
        }
    }
}
