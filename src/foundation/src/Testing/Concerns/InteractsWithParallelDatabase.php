<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Database\QueryException;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

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

        $database = $config->get("database.connections.{$connection}.database", '');

        if ($database === ':memory:' || $database === '') {
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
        $token = env('TEST_TOKEN');

        if ($token === null) {
            return;
        }

        $config = $this->app->make('config');
        $connection = $config->get('database.default');

        if ($config->get("database.connections.{$connection}") === null) {
            return;
        }

        $database = $config->get("database.connections.{$connection}.database", '');

        if ($database === ':memory:' || $database === '') {
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
     * Get the per-worker test database name.
     */
    protected function parallelTestDatabase(string $database, string $token): string
    {
        if (isset(static::$originalDatabaseName)) {
            $database = static::$originalDatabaseName;
        } elseif (preg_match('/^(.*)_test_[^\/\\\\]+$/', $database, $matches) === 1) {
            $database = $matches[1];
            static::$originalDatabaseName = $database;
        } else {
            static::$originalDatabaseName = $database;
        }

        return "{$database}_test_{$token}";
    }
}
