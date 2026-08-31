<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Database\ConfigurationUrlParser;
use Hypervel\Database\QueryException;
use Hypervel\Database\SQLiteDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Provides per-worker database isolation for parallel testing.
 *
 * Two-phase approach:
 * 1. Config rewrite (early, in CreatesApplication): rewrites the default
 *    connection's database name to {database}_test_{token} before
 *    defineEnvironment() runs, so custom connections derived from the
 *    default connection inherit the correct database name.
 * 2. Database creation (later, in database traits): ensures the per-worker
 *    database exists, creating it on demand if needed.
 *
 * In-memory SQLite databases are skipped — each worker process gets its
 * own memory space naturally.
 */
trait InteractsWithParallelDatabase
{
    /**
     * Rewrite the default connection's database name for parallel testing.
     *
     * Config-only — does not create connections or purge pools. Called early
     * in CreatesApplication (after config is loaded, before defineEnvironment)
     * so that custom connections derived from the default connection inherit
     * the per-worker database name.
     *
     * No-op when not running in parallel or when using in-memory SQLite.
     * @param mixed $app
     */
    protected function configureParallelDatabaseName($app): void
    {
        if (! empty($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'])) {
            return;
        }

        $token = env('TEST_TOKEN');

        if ($token === null) {
            return;
        }

        /** @var ConfigContract $config */
        $config = $app->make('config');
        $connection = $config->get('database.default');

        if (! is_string($connection)) {
            return;
        }

        $configurationPath = "database.connections.{$connection}";
        $declaredConfiguration = $config->get($configurationPath);

        // Skip if no real connection is configured (e.g., mocked test apps)
        if (! is_array($declaredConfiguration)) {
            return;
        }

        $configuration = $this->normalizeParallelDatabaseConfiguration($declaredConfiguration);
        $driver = $configuration['driver'] ?? null;
        $database = $configuration['database'] ?? null;

        if (! is_string($database) || ! $this->shouldManageParallelDatabase($driver, $database)) {
            return;
        }

        $configuration['database'] = $this->parallelTestDatabase($database, (string) $token);

        if ($configuration !== $declaredConfiguration) {
            $config->set($configurationPath, $configuration);
        }
    }

    /**
     * Ensure the per-worker database exists, creating it if needed.
     *
     * Called from database testing traits (RefreshDatabase, DatabaseMigrations,
     * DatabaseTruncation, DatabaseTransactions) after the app is booted and
     * connections are available.
     * The config has already been rewritten by configureParallelDatabaseName().
     *
     * No-op when not running in parallel or when using in-memory SQLite.
     */
    protected function ensureParallelDatabaseExists(): void
    {
        if (! empty($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'])) {
            return;
        }

        $token = env('TEST_TOKEN');

        if ($token === null) {
            return;
        }

        /** @var ConfigContract $config */
        $config = $this->app->make('config');
        $connection = $config->get('database.default');

        if (! is_string($connection)) {
            return;
        }

        $configurationPath = "database.connections.{$connection}";
        $declaredConfiguration = $config->get($configurationPath);

        if (! is_array($declaredConfiguration)) {
            return;
        }

        $configuration = $this->normalizeParallelDatabaseConfiguration($declaredConfiguration);
        $driver = $configuration['driver'] ?? null;
        $database = $configuration['database'] ?? null;

        if (! is_string($database) || ! $this->shouldManageParallelDatabase($driver, $database)) {
            return;
        }

        if ($configuration !== $declaredConfiguration) {
            $config->set($configurationPath, $configuration);
        }

        // The database name has already been suffixed by configureParallelDatabaseName().
        // Try to connect — if it fails, create the database from the base connection.
        try {
            Schema::connection($connection)->hasTable('__parallel_check');
        } catch (QueryException) {
            $baseConfiguration = $configuration;
            $baseConfiguration['database'] = $this->parallelDatabaseBaseName($database, (string) $token);

            // Switch to the original database to run CREATE DATABASE.
            $config->set($configurationPath, $baseConfiguration);
            DB::purge($connection);

            Schema::connection($connection)->createDatabase($database);

            // Switch back to the per-worker database.
            $config->set($configurationPath, $configuration);
            DB::purge($connection);
        }
    }

    /**
     * Determine if the database should be managed for parallel testing.
     */
    protected function shouldManageParallelDatabase(mixed $driver, string $database): bool
    {
        if ($database === '') {
            return false;
        }

        if ($driver !== 'sqlite') {
            return true;
        }

        if (SQLiteDatabase::isInMemory($database)) {
            return false;
        }

        if (SQLiteDatabase::isUri($database)) {
            throw new InvalidArgumentException(
                'SQLite URI databases cannot be automatically managed during parallel testing. '
                . 'Configure a plain filesystem path or run with --without-databases.'
            );
        }

        return true;
    }

    /**
     * Get the per-worker test database name.
     */
    protected function parallelTestDatabase(string $database, string $token): string
    {
        $database = $this->parallelDatabaseBaseName($database, $token);

        return "{$database}_test_{$token}";
    }

    /**
     * Get the database name before any parallel-testing suffix.
     */
    private function parallelDatabaseBaseName(string $database, string $token): string
    {
        $suffix = "_test_{$token}";

        return str_ends_with($database, $suffix)
            ? substr($database, 0, -strlen($suffix))
            : $database;
    }

    /**
     * Normalize and validate a parallel database configuration.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function normalizeParallelDatabaseConfiguration(array $configuration): array
    {
        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);

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

        return $configuration;
    }
}
