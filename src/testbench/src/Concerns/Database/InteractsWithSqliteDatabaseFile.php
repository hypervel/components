<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns\Database;

use Hypervel\Database\SQLiteDatabase;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AfterClass;
use RuntimeException;
use Throwable;

trait InteractsWithSqliteDatabaseFile
{
    use InteractsWithPublishedFiles;

    /**
     * List of generated files.
     *
     * @var array<int, string>
     */
    protected array $files = [];

    /**
     * Purge the sqlite connection before swapping the runtime database file.
     *
     * These helpers replace `database.sqlite` on disk while the parent test
     * process and remote CLI commands share the same runtime base path. The
     * testing resolver caches sqlite connections per worker, so the parent
     * process must drop its cached PDO handle before reading a newly swapped file.
     */
    protected function purgeSqliteConnection(): void
    {
        DB::purge('sqlite');
    }

    /**
     * Get the base sqlite database path before any parallel suffix is applied.
     */
    protected function baseSqliteDatabasePath(): string
    {
        $database = $this->app->make('config')->string('database.connections.sqlite.database');
        $token = env('TEST_TOKEN');

        if ($token === null) {
            return $database;
        }

        $suffix = "_test_{$token}";

        return str_ends_with($database, $suffix)
            ? substr($database, 0, -strlen($suffix))
            : $database;
    }

    /**
     * Get the sqlite database path used by remote CLI commands in parallel.
     *
     * The commander subprocess boots a fresh Testbench application. When
     * `DB_CONNECTION=sqlite` and ParaTest sets `TEST_TOKEN`, Testbench rewrites
     * the sqlite database path to a token-suffixed file. The parent test process
     * must point its sqlite connection at that same file before asserting on it.
     */
    protected function activeSqliteDatabasePath(): string
    {
        $database = $this->baseSqliteDatabasePath();
        $token = env('TEST_TOKEN');

        if ($token === null || $database === '' || SQLiteDatabase::isInMemory($database)) {
            return $database;
        }

        return "{$database}_test_{$token}";
    }

    /**
     * Temporarily point the sqlite connection at the active runtime database file.
     */
    protected function useActiveSqliteDatabasePath(callable $callback): void
    {
        $config = $this->app->make('config');
        $originalDatabase = $config->string('database.connections.sqlite.database');
        $activeDatabase = $this->activeSqliteDatabasePath();

        if ($originalDatabase === $activeDatabase) {
            value($callback);

            return;
        }

        $config->set('database.connections.sqlite.database', $activeDatabase);
        $failure = null;

        try {
            $this->purgeSqliteConnection();
            value($callback);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        try {
            $this->purgeSqliteConnection();
        } catch (Throwable $throwable) {
            $failure ??= $throwable;
        }

        $config->set('database.connections.sqlite.database', $originalDatabase);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Drop Sqlite database.
     *
     * @api
     */
    protected function withoutSqliteDatabase(callable $callback): void
    {
        $time = time();
        $filesystem = $this->app->make(Filesystem::class);
        $baseDatabase = $this->baseSqliteDatabasePath();
        $activeDatabase = $this->activeSqliteDatabasePath();
        $databases = array_values(array_unique([$baseDatabase, $activeDatabase]));

        foreach ($databases as $database) {
            if (SQLiteDatabase::isInMemory($database) || SQLiteDatabase::isUri($database)) {
                throw new InvalidArgumentException(
                    "SQLite database [{$database}] is not a local filesystem path."
                );
            }
        }

        $originals = [];
        $backups = [];
        $failure = null;

        foreach ($databases as $database) {
            $originals[$database] = $filesystem->exists($database);
        }

        try {
            foreach ($databases as $database) {
                if (! $originals[$database]) {
                    continue;
                }

                $backup = "{$database}.backup-{$time}";
                $this->purgeSqliteConnection();

                if (! $filesystem->move($database, $backup)) {
                    throw new RuntimeException("Unable to back up SQLite database [{$database}].");
                }

                $backups[$database] = $backup;
            }

            value($callback);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        } finally {
            foreach ($databases as $database) {
                if ($originals[$database] && ! isset($backups[$database])) {
                    continue;
                }

                try {
                    $this->purgeSqliteConnection();
                    $restoreFailure = null;

                    if ($filesystem->exists($database) && ! $filesystem->delete($database)) {
                        $restoreFailure = new RuntimeException(
                            "Unable to remove temporary SQLite database [{$database}]."
                        );
                    }

                    if (isset($backups[$database])
                        && ! $filesystem->move($backups[$database], $database)) {
                        $restoreFailure ??= new RuntimeException(
                            "Unable to restore SQLite database [{$database}]."
                        );
                    }

                    if ($restoreFailure !== null) {
                        throw $restoreFailure;
                    }
                } catch (Throwable $throwable) {
                    $failure ??= $throwable;
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Drop and create a new Sqlite database.
     *
     * @api
     */
    protected function withSqliteDatabase(callable $callback): void
    {
        $this->withoutSqliteDatabase(function () use ($callback) {
            $filesystem = $this->app->make(Filesystem::class);
            $baseDatabase = $this->baseSqliteDatabasePath();
            $activeDatabase = $this->activeSqliteDatabasePath();
            $exampleDatabase = "{$baseDatabase}.example";
            $createdBaseDatabase = false;
            $createdActiveDatabase = false;

            $this->purgeSqliteConnection();

            if (! $filesystem->exists($baseDatabase)) {
                if (! $filesystem->copy($exampleDatabase, $baseDatabase)) {
                    throw new RuntimeException("Unable to create SQLite database [{$baseDatabase}].");
                }

                $createdBaseDatabase = true;
            }

            if ($activeDatabase !== $baseDatabase && ! $filesystem->exists($activeDatabase)) {
                $this->purgeSqliteConnection();

                if (! $filesystem->copy($exampleDatabase, $activeDatabase)) {
                    throw new RuntimeException("Unable to create SQLite database [{$activeDatabase}].");
                }

                $createdActiveDatabase = true;
            }

            $failure = null;

            try {
                $this->useActiveSqliteDatabasePath($callback);
            } catch (Throwable $throwable) {
                $failure = $throwable;
            } finally {
                try {
                    $this->purgeSqliteConnection();
                } catch (Throwable $throwable) {
                    $failure ??= $throwable;
                }

                if ($createdBaseDatabase
                    && $filesystem->exists($baseDatabase)
                    && ! $filesystem->delete($baseDatabase)) {
                    $failure ??= new RuntimeException("Unable to remove SQLite database [{$baseDatabase}].");
                }

                if ($createdActiveDatabase
                    && $filesystem->exists($activeDatabase)
                    && ! $filesystem->delete($activeDatabase)) {
                    $failure ??= new RuntimeException("Unable to remove SQLite database [{$activeDatabase}].");
                }
            }

            if ($failure !== null) {
                throw $failure;
            }
        });
    }

    /**
     * Clean up backup Sqlite database files after class teardown.
     *
     * @codeCoverageIgnore
     */
    #[AfterClass]
    public static function cleanupBackupSqliteDatabaseFilesOnFailed(): void
    {
        $filesystem = new Filesystem;

        $filesystem->delete(
            (new Collection([
                ...$filesystem->glob(database_path('database.sqlite*.backup-*')),
                ...$filesystem->glob(database_path('database.sqlite-*')),
                ...$filesystem->glob(database_path('database.sqlite_test_*')),
            ]))->filter(static fn ($file) => $filesystem->exists($file))
                ->all()
        );
    }
}
