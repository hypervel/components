<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\PgSQL\Connectors\PostgresConnector;
use Hyperf\Database\SQLite\Connectors\SQLiteConnector;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Support\Facades\DB;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Throwable;

/**
 * Base test case for database integration tests.
 *
 * Provides parallel-safe database testing infrastructure:
 * - Uses TEST_TOKEN env var (from paratest) to create unique table prefixes per worker
 * - Configures database connection from environment variables
 * - Drops test tables in tearDown (safe for parallel execution)
 *
 * Subclasses should override configurePackage() to add package-specific
 * configuration and implement getDatabaseDriver() to specify the driver.
 *
 * NOTE: Concrete test classes extending this (or its subclasses) MUST add
 * @group integration and @group {driver}-integration for proper test filtering in CI.
 *
 * @internal
 * @coversNothing
 */
abstract class DatabaseIntegrationTestCase extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Base table prefix for integration tests.
     */
    protected string $basePrefix = 'dbtest';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $tablePrefix;

    /**
     * Tables created during tests (for cleanup).
     *
     * @var array<string>
     */
    protected array $createdTables = [];

    protected function setUp(): void
    {
        if (! env('RUN_DATABASE_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Database integration tests are disabled. Set RUN_DATABASE_INTEGRATION_TESTS=true to enable.'
            );
        }

        $this->computeTablePrefix();

        parent::setUp();

        $this->configureDatabase();
        $this->configurePackage();
    }

    /**
     * Tear down inside coroutine - runs INSIDE the Swoole coroutine context.
     *
     * Database operations require coroutine context in Swoole/Hyperf.
     */
    protected function tearDownInCoroutine(): void
    {
        $this->dropTestTables();
    }

    /**
     * Compute parallel-safe prefix based on TEST_TOKEN from paratest.
     *
     * Each worker gets a unique prefix (e.g., dbtest_1_, dbtest_2_).
     * This provides isolation without needing separate databases.
     */
    protected function computeTablePrefix(): void
    {
        $testToken = env('TEST_TOKEN', '');

        if ($testToken !== '') {
            $this->tablePrefix = "{$this->basePrefix}_{$testToken}_";
        } else {
            $this->tablePrefix = "{$this->basePrefix}_";
        }
    }

    /**
     * Configure database connection settings from environment variables.
     */
    protected function configureDatabase(): void
    {
        $driver = $this->getDatabaseDriver();
        $config = $this->app->get(ConfigInterface::class);

        // Register driver-specific connectors (not loaded by default in test environment)
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
     *
     * MySQL connector is registered by default. PostgreSQL and SQLite
     * connectors must be explicitly registered in test environment.
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
        return [
            'driver' => 'mysql',
            'host' => env('MYSQL_HOST', '127.0.0.1'),
            'port' => (int) env('MYSQL_PORT', 3306),
            'database' => env('MYSQL_DATABASE', 'testing'),
            'username' => env('MYSQL_USERNAME', 'root'),
            'password' => env('MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $this->tablePrefix,
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
        return [
            'driver' => 'pgsql',
            'host' => env('PGSQL_HOST', '127.0.0.1'),
            'port' => (int) env('PGSQL_PORT', 5432),
            'database' => env('PGSQL_DATABASE', 'testing'),
            'username' => env('PGSQL_USERNAME', 'postgres'),
            'password' => env('PGSQL_PASSWORD', ''),
            'charset' => 'utf8',
            'schema' => 'public',
            'prefix' => $this->tablePrefix,
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
            'prefix' => $this->tablePrefix,
        ];
    }

    /**
     * Configure package-specific settings.
     *
     * Override this method in subclasses to add package-specific configuration.
     */
    protected function configurePackage(): void
    {
        // Override in subclasses
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
     * Create a test table and track it for cleanup.
     *
     * Drops the table first if it exists (from a previous failed run),
     * then creates it fresh.
     *
     * @param string $name Table name (without prefix)
     * @param callable $callback Schema builder callback
     */
    protected function createTestTable(string $name, callable $callback): void
    {
        $this->createdTables[] = $name;

        // Drop first in case it exists from a previous failed run
        try {
            $this->getSchemaBuilder()->dropIfExists($name);
        } catch (Throwable) {
            // Ignore errors during cleanup
        }

        $this->getSchemaBuilder()->create($name, $callback);
    }

    /**
     * Drop all test tables created during this test.
     */
    protected function dropTestTables(): void
    {
        foreach (array_reverse($this->createdTables) as $table) {
            try {
                $this->getSchemaBuilder()->dropIfExists($table);
            } catch (Throwable) {
                // Ignore errors during cleanup
            }
        }

        $this->createdTables = [];
    }

    /**
     * Get full table name with prefix.
     */
    protected function getFullTableName(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
