<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Database\ConfigurationUrlParser;
use Hypervel\Database\QueryException;
use Hypervel\Database\SQLiteDatabase;
use Hypervel\Foundation\Testing;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\Facades\Schema;
use InvalidArgumentException;

trait TestDatabases
{
    /**
     * Indicates if the test database schema is up to date.
     *
     * Intentionally process-lifetime during a parallel test worker run. The
     * worker should migrate its test database once, not before every test that
     * uses DatabaseTransactions.
     */
    protected static bool $schemaIsUpToDate = false;

    /**
     * Boot a test database.
     */
    protected function bootTestDatabase(): void
    {
        ParallelTesting::setUpProcess(function () {
            $this->whenNotUsingInMemoryDatabase(function (string $database) {
                if (ParallelTesting::option('recreate_databases')) {
                    Schema::dropDatabaseIfExists(
                        $this->testDatabase($database)
                    );
                }
            });
        });

        ParallelTesting::setUpTestCase(function ($testCase) {
            $uses = class_uses_recursive(get_class($testCase));

            $databaseTraits = [
                Testing\DatabaseMigrations::class,
                Testing\DatabaseTransactions::class,
                Testing\DatabaseTruncation::class,
                Testing\RefreshDatabase::class,
            ];

            if (Arr::hasAny($uses, $databaseTraits) && ! ParallelTesting::option('without_databases')) {
                $this->whenNotUsingInMemoryDatabase(function (string $database) use ($uses) {
                    [$testDatabase, $created] = $this->ensureTestDatabaseExists($database);

                    $this->switchToDatabase($testDatabase);

                    if ($created) {
                        ParallelTesting::callSetUpTestDatabaseBeforeMigratingCallbacks($testDatabase);
                    }

                    if (isset($uses[Testing\DatabaseTransactions::class])) {
                        $this->ensureSchemaIsUpToDate();
                    }

                    if ($created) {
                        ParallelTesting::callSetUpTestDatabaseCallbacks($testDatabase);
                    }
                });
            }
        });

        ParallelTesting::tearDownProcess(function () {
            $this->whenNotUsingInMemoryDatabase(function (string $database) {
                if (ParallelTesting::option('drop_databases')) {
                    Schema::dropDatabaseIfExists(
                        $this->testDatabase($database)
                    );
                }
            });
        });
    }

    /**
     * Ensure a test database exists and return its name.
     *
     * @return array{string, bool}
     */
    protected function ensureTestDatabaseExists(string $database): array
    {
        $testDatabase = $this->testDatabase($database);

        try {
            $this->usingDatabase($testDatabase, function () {
                Schema::hasTable('dummy');
            });
        } catch (QueryException) {
            $this->usingDatabase($database, function () use ($testDatabase) {
                Schema::dropDatabaseIfExists($testDatabase);
                Schema::createDatabase($testDatabase);
            });

            return [$testDatabase, true];
        }

        return [$testDatabase, false];
    }

    /**
     * Ensure the current database test schema is up to date.
     */
    protected function ensureSchemaIsUpToDate(): void
    {
        if (! static::$schemaIsUpToDate) {
            Artisan::call('migrate');

            static::$schemaIsUpToDate = true;
        }
    }

    /**
     * Run the given callable using the given database.
     */
    protected function usingDatabase(string $database, callable $callable): void
    {
        $original = DB::getConfig('database');

        try {
            $this->switchToDatabase($database);
            $callable();
        } finally {
            $this->switchToDatabase($original);
        }
    }

    /**
     * Apply the given callback when tests are not using in-memory database.
     */
    protected function whenNotUsingInMemoryDatabase(callable $callback): void
    {
        if (ParallelTesting::option('without_databases')) {
            return;
        }

        /** @var ConfigContract $config */
        $config = config();
        $default = $config->string('database.default');
        $configuration = (new ConfigurationUrlParser)->parseConfiguration(
            $config->array("database.connections.{$default}")
        );
        $this->validateManagedDatabaseTopology($configuration);

        /** @var string $database */
        $database = DB::getConfig('database');

        if (DB::getConfig('driver') === 'sqlite') {
            if (SQLiteDatabase::isInMemory($database)) {
                return;
            }

            if (SQLiteDatabase::isUri($database)) {
                throw new InvalidArgumentException(
                    'SQLite URI databases cannot be automatically managed during parallel testing. '
                    . 'Configure a plain filesystem path or run with --without-databases.'
                );
            }
        }

        $callback($database);
    }

    /**
     * Switch to the given database.
     */
    protected function switchToDatabase(string $database): void
    {
        DB::purge();

        /** @var ConfigContract $config */
        $config = config();
        $default = $config->string('database.default');
        $configurationPath = "database.connections.{$default}";
        $configuration = (new ConfigurationUrlParser)->parseConfiguration(
            $config->array($configurationPath)
        );
        $this->validateManagedDatabaseTopology($configuration);
        $configuration['database'] = $database;

        $config->set($configurationPath, $configuration);
    }

    /**
     * Get the test database name.
     */
    protected function testDatabase(string $database): string
    {
        $token = ParallelTesting::token();
        $suffix = "_test_{$token}";

        return str_ends_with($database, $suffix)
            ? $database
            : "{$database}{$suffix}";
    }

    /**
     * Validate a managed database topology.
     *
     * @param array<string, mixed> $configuration
     */
    private function validateManagedDatabaseTopology(array $configuration): void
    {
        foreach (['read', 'write'] as $role) {
            $endpoints = $configuration[$role] ?? null;

            if (! is_array($endpoints)) {
                continue;
            }

            $hasEndpointIdentity = array_key_exists('database', $endpoints)
                || array_key_exists('url', $endpoints);

            if (isset($endpoints[0])) {
                foreach ($endpoints as $endpoint) {
                    if (is_array($endpoint)
                        && (array_key_exists('database', $endpoint) || array_key_exists('url', $endpoint))) {
                        $hasEndpointIdentity = true;

                        break;
                    }
                }
            }

            if ($hasEndpointIdentity) {
                throw new InvalidArgumentException(
                    'Read/write connections with endpoint-specific databases or URLs cannot be automatically managed during parallel testing. '
                    . 'Configure a single database identity or run with --without-databases.'
                );
            }
        }
    }
}
