<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Connectors\PostgresConnector;
use Hypervel\Database\Connectors\SQLiteConnector;
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
        $driver = $this->getDatabaseDriver();

        if ($this->shouldSkipForDriver($driver)) {
            $this->markTestSkipped(
                "Integration tests for {$driver} are disabled. Set the appropriate RUN_*_INTEGRATION_TESTS=true to enable."
            );
        }

        parent::setUp();

        $this->configureDatabase();
    }

    /**
     * Determine if tests should be skipped for the given driver.
     */
    protected function shouldSkipForDriver(string $driver): bool
    {
        return match ($driver) {
            'pgsql' => ! env('RUN_PGSQL_INTEGRATION_TESTS', false),
            'mysql' => ! env('RUN_MYSQL_INTEGRATION_TESTS', false),
            'sqlite' => false, // SQLite tests always run
            default => true,
        };
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

        // Set Hyperf-style config (used by DbPool/ConnectionResolver)
        $config->set("databases.{$driver}", $connectionConfig);
        $config->set('databases.default', $connectionConfig);

        // Set Laravel-style config (used by RefreshDatabase trait)
        $config->set("database.connections.{$driver}", $connectionConfig);
        $config->set('database.default', $driver);
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
     * Get SQLite connection configuration.
     *
     * Uses :memory: for fast in-memory testing. The RegisterSQLiteConnectionListener
     * ensures all pooled connections share the same in-memory database by storing
     * a persistent PDO in the container.
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

    /**
     * The database connections that should have transactions.
     *
     * Override to use the test's driver instead of the default connection.
     *
     * @return array<int, string>
     */
    protected function connectionsToTransact(): array
    {
        return [$this->getDatabaseDriver()];
    }
}
