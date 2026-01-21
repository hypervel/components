<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\PgSQL\Connectors\PostgresConnector;
use Hyperf\Database\SQLite\Connectors\SQLiteConnector;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

/**
 * Base test case for database integration tests.
 *
 * Supports parallel testing via TEST_TOKEN environment variable - each
 * worker gets its own database (e.g., testing_1, testing_2).
 *
 * Subclasses that need migrations should use RefreshDatabase trait and implement:
 * - getDatabaseDriver(): Return the database driver name
 * - migrateFreshUsing(): Return migration options including path
 *
 * @internal
 * @coversNothing
 */
abstract class DatabaseIntegrationTestCase extends TestCase
{

    protected function setUp(): void
    {
        if (! env('RUN_DATABASE_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Database integration tests are disabled. Set RUN_DATABASE_INTEGRATION_TESTS=true to enable.'
            );
        }

        parent::setUp();

        $this->configureDatabase();
    }

    /**
     * Configure database connection settings from environment variables.
     *
     * Uses ParallelTesting to get worker-specific database names when
     * running with paratest.
     */
    protected function configureDatabase(): void
    {
        $driver = $this->getDatabaseDriver();
        $config = $this->app->get(ConfigInterface::class);

        $this->registerConnectors($driver);

        $connectionConfig = match ($driver) {
            'mysql' => $this->getMySqlConnectionConfig(),
            'pgsql' => $this->getPostgresConnectionConfig(),
            'sqlite' => $this->getSqliteConnectionConfig(),
            default => throw new InvalidArgumentException("Unsupported driver: {$driver}"),
        };

        $config->set("databases.{$driver}", $connectionConfig);
        $config->set('databases.default', $connectionConfig);
    }

    /**
     * Register database connectors for non-MySQL drivers.
     */
    protected function registerConnectors(string $driver): void
    {
        match ($driver) {
            'pgsql' => $this->app->set('db.connector.pgsql', new PostgresConnector()),
            'sqlite' => $this->app->set('db.connector.sqlite', new SQLiteConnector()),
            default => null,
        };
    }

    /**
     * Get MySQL connection configuration.
     *
     * @return array<string, mixed>
     */
    protected function getMySqlConnectionConfig(): array
    {
        $baseDatabase = env('MYSQL_DATABASE', 'testing');

        return [
            'driver' => 'mysql',
            'host' => env('MYSQL_HOST', '127.0.0.1'),
            'port' => (int) env('MYSQL_PORT', 3306),
            'database' => ParallelTesting::databaseName($baseDatabase),
            'username' => env('MYSQL_USERNAME', 'root'),
            'password' => env('MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ];
    }

    /**
     * Get PostgreSQL connection configuration.
     *
     * @return array<string, mixed>
     */
    protected function getPostgresConnectionConfig(): array
    {
        $baseDatabase = env('PGSQL_DATABASE', 'testing');

        return [
            'driver' => 'pgsql',
            'host' => env('PGSQL_HOST', '127.0.0.1'),
            'port' => (int) env('PGSQL_PORT', 5432),
            'database' => ParallelTesting::databaseName($baseDatabase),
            'username' => env('PGSQL_USERNAME', 'postgres'),
            'password' => env('PGSQL_PASSWORD', ''),
            'charset' => 'utf8',
            'schema' => 'public',
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ];
    }

    /**
     * Get SQLite connection configuration (in-memory).
     *
     * @return array<string, mixed>
     */
    protected function getSqliteConnectionConfig(): array
    {
        return [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ];
    }

    /**
     * Get the database driver for this test class.
     */
    abstract protected function getDatabaseDriver(): string;

    /**
     * Get the schema builder for the test connection.
     */
    protected function getSchemaBuilder(): SchemaBuilder
    {
        return Schema::connection($this->getDatabaseDriver());
    }

    /**
     * Get the database connection for the test.
     */
    protected function db(): ConnectionInterface
    {
        return DB::connection($this->getDatabaseDriver());
    }

    /**
     * Get the connection name for RefreshDatabase.
     */
    protected function getRefreshConnection(): string
    {
        return $this->getDatabaseDriver();
    }
}
