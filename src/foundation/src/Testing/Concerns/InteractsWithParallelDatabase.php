<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

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
     * The original database name before parallel suffixing.
     */
    protected static ?string $originalDatabaseName = null;

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

        $config = $app->make('config');
        $connection = $config->get('database.default');

        // Skip if no real connection is configured (e.g., mocked test apps)
        if ($config->get("database.connections.{$connection}") === null) {
            return;
        }

        $driver = $config->get("database.connections.{$connection}.driver");
        $database = $config->get("database.connections.{$connection}.database");

        if (! is_string($database) || ! $this->shouldManageParallelDatabase($driver, $database)) {
            return;
        }

        $testDatabase = $this->parallelTestDatabase($database, (string) $token);

        $config->set("database.connections.{$connection}.database", $testDatabase);
    }

    /**
     * Ensure the per-worker database exists, creating it if needed.
     *
     * Called from database testing traits (RefreshDatabase, DatabaseMigrations,
     * DatabaseTransactions) after the app is booted and connections are available.
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

        $config = $this->app->make('config');
        $connection = $config->get('database.default');

        if ($config->get("database.connections.{$connection}") === null) {
            return;
        }

        $driver = $config->get("database.connections.{$connection}.driver");
        $database = $config->get("database.connections.{$connection}.database");

        if (! is_string($database) || ! $this->shouldManageParallelDatabase($driver, $database)) {
            return;
        }

        // The database name has already been suffixed by configureParallelDatabaseName().
        // Try to connect — if it fails, create the database from the base connection.
        try {
            Schema::connection($connection)->hasTable('__parallel_check');
        } catch (QueryException) {
            // Switch to the original database to run CREATE DATABASE
            $config->set("database.connections.{$connection}.database", static::$originalDatabaseName);
            DB::purge($connection);

            Schema::connection($connection)->createDatabase($database);

            // Switch back to the per-worker database
            $config->set("database.connections.{$connection}.database", $database);
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
        if (isset(static::$originalDatabaseName)) {
            $database = static::$originalDatabaseName;
        } elseif (preg_match('/^(.*)_test_[^\/\\\]+$/', $database, $matches) === 1) {
            $database = $matches[1];
            static::$originalDatabaseName = $database;
        } else {
            static::$originalDatabaseName = $database;
        }

        return "{$database}_test_{$token}";
    }
}
