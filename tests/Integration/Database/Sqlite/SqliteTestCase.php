<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Database\SQLiteDatabase;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Override;

#[RequiresDatabase('sqlite')]
abstract class SqliteTestCase extends DatabaseTestCase
{
    #[Override]
    protected function setUp(): void
    {
        $this->ensureSqliteDatabaseFileExists();

        parent::setUp();
    }

    /**
     * Check if configured for in-memory SQLite database.
     *
     * Uses env() so it can be called before the app boots.
     */
    protected function isConfiguredForInMemoryDatabase(): bool
    {
        return SQLiteDatabase::isInMemory($this->getConfiguredDatabasePath());
    }

    /**
     * Get the configured SQLite database path from env.
     *
     * Uses env() so it can be called before the app boots.
     */
    protected function getConfiguredDatabasePath(): string
    {
        return env('DB_DATABASE', ':memory:');
    }

    /**
     * Ensure the SQLite database file exists before connecting.
     *
     * The SQLite connector requires the file to exist (it won't auto-create).
     * This runs before parent::setUp() which establishes the connection.
     */
    protected function ensureSqliteDatabaseFileExists(): void
    {
        $path = $this->getConfiguredDatabasePath();

        // URI names pass to SQLite unchanged and are not literal filesystem paths.
        if (SQLiteDatabase::isInMemory($path) || SQLiteDatabase::isUri($path)) {
            return;
        }

        if (! file_exists($path)) {
            touch($path);
        }
    }

    /**
     * Delete the SQLite database file and its WAL companion files.
     *
     * Use this when a test needs a completely fresh database file (not just
     * fresh tables). The file will be recreated by ensureSqliteDatabaseFileExists()
     * in the next test's setUp.
     */
    protected function deleteSqliteDatabaseFile(?string $path = null): void
    {
        $path ??= $this->app->make('config')->get('database.connections.sqlite.database');

        // URI names pass to SQLite unchanged and are not literal filesystem paths.
        if (SQLiteDatabase::isInMemory($path) || SQLiteDatabase::isUri($path)) {
            return;
        }

        (new Filesystem)->delete([
            $path,
            $path . '-wal',
            $path . '-shm',
        ]);
    }
}
