<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns\Database;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use PHPUnit\Framework\Attributes\AfterClass;

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

        if ($token === null || $database === '' || $database === ':memory:') {
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
        $this->purgeSqliteConnection();

        try {
            value($callback);
        } finally {
            $this->purgeSqliteConnection();
            $config->set('database.connections.sqlite.database', $originalDatabase);
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
        $filesystem = new Filesystem();

        $baseDatabase = $this->baseSqliteDatabasePath();
        $activeDatabase = $this->activeSqliteDatabasePath();

        $this->purgeSqliteConnection();

        if ($filesystem->exists($baseDatabase)) {
            $filesystem->move($baseDatabase, $temporaryBaseDatabase = "{$baseDatabase}.backup-{$time}");

            $this->files[] = $temporaryBaseDatabase;
        }

        if ($activeDatabase !== $baseDatabase && $filesystem->exists($activeDatabase)) {
            $filesystem->move($activeDatabase, $temporaryActiveDatabase = "{$activeDatabase}.backup-{$time}");

            $this->files[] = $temporaryActiveDatabase;
        }

        try {
            value($callback);
        } finally {
            $this->purgeSqliteConnection();

            if (isset($temporaryBaseDatabase)) {
                $filesystem->move($temporaryBaseDatabase, $baseDatabase);
            }

            if (isset($temporaryActiveDatabase)) {
                $filesystem->move($temporaryActiveDatabase, $activeDatabase);
            }
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
            $filesystem = new Filesystem();

            $baseDatabase = $this->baseSqliteDatabasePath();
            $activeDatabase = $this->activeSqliteDatabasePath();
            $exampleDatabase = "{$baseDatabase}.example";

            $this->purgeSqliteConnection();

            if (! $filesystem->exists($baseDatabase)) {
                $filesystem->copy($exampleBaseDatabase = $exampleDatabase, $baseDatabase);
            }

            if ($activeDatabase !== $baseDatabase && ! $filesystem->exists($activeDatabase)) {
                $filesystem->copy($exampleActiveDatabase = $exampleDatabase, $activeDatabase);
            }

            try {
                $this->useActiveSqliteDatabasePath($callback);
            } finally {
                $this->purgeSqliteConnection();

                if (isset($exampleBaseDatabase)) {
                    $filesystem->delete($baseDatabase);
                }

                if (isset($exampleActiveDatabase)) {
                    $filesystem->delete($activeDatabase);
                }
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
        $filesystem = new Filesystem();

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
