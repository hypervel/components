<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns\Database;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class InteractsWithSqliteDatabaseFileTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    protected string $temporaryDirectory;

    protected string $baseDatabase;

    protected string $activeDatabase;

    protected SqliteFileTestFilesystem $testFilesystem;

    protected ?int $failingPurge = null;

    protected int $purgeCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = ParallelTesting::tempDir('InteractsWithSqliteDatabaseFileTest');
        $this->baseDatabase = $this->temporaryDirectory . '/database.sqlite';
        $this->activeDatabase = $this->temporaryDirectory . '/database-active.sqlite';
        $this->testFilesystem = new SqliteFileTestFilesystem;

        $this->testFilesystem->deleteDirectory($this->temporaryDirectory);
        $this->testFilesystem->makeDirectory($this->temporaryDirectory, recursive: true);
        $this->testFilesystem->operations = [];
        $this->app->instance(Filesystem::class, $this->testFilesystem);
    }

    protected function tearDown(): void
    {
        $this->testFilesystem->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('unsupportedDatabaseIdentifiers')]
    public function itRejectsNonFileDatabaseIdentifiers(string $database): void
    {
        $this->baseDatabase = $database;
        $this->activeDatabase = $database;

        try {
            $this->withoutSqliteDatabase(static function (): void {
            });
            $this->fail('Expected the SQLite identifier to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($database, $exception->getMessage());
        }

        $this->assertSame([], $this->testFilesystem->operations);
    }

    /**
     * Provide SQLite identifiers that are not local files.
     */
    public static function unsupportedDatabaseIdentifiers(): array
    {
        return [
            'memory' => [':memory:'],
            'memory URI' => ['file::memory:'],
            'file URI' => ['file:/tmp/testbench.sqlite?mode=rwc'],
        ];
    }

    #[Test]
    public function itLeavesTheOriginalWhenItsBackupMoveFails(): void
    {
        file_put_contents($this->baseDatabase, 'base');
        $this->testFilesystem->failMoveFrom = $this->baseDatabase;

        try {
            $this->withoutSqliteDatabase(static function (): void {
            });
            $this->fail('Expected the backup move to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Unable to back up SQLite database [{$this->baseDatabase}].",
                $exception->getMessage(),
            );
        }

        $this->assertSame('base', file_get_contents($this->baseDatabase));
        $this->assertSame([], glob($this->baseDatabase . '.backup-*') ?: []);
    }

    #[Test]
    public function itRestoresAnOwnedBaseBackupWhenTheActiveBackupFails(): void
    {
        file_put_contents($this->baseDatabase, 'base');
        file_put_contents($this->activeDatabase, 'active');
        $this->testFilesystem->failMoveFrom = $this->activeDatabase;

        try {
            $this->withoutSqliteDatabase(static function (): void {
            });
            $this->fail('Expected the active backup move to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Unable to back up SQLite database [{$this->activeDatabase}].",
                $exception->getMessage(),
            );
        }

        $this->assertSame('base', file_get_contents($this->baseDatabase));
        $this->assertSame('active', file_get_contents($this->activeDatabase));
        $this->assertSame([], glob($this->temporaryDirectory . '/*.backup-*') ?: []);
    }

    #[Test]
    public function itRestoresEveryOwnedDatabaseAndPreservesTheCallbackFailure(): void
    {
        file_put_contents($this->baseDatabase, 'base');
        file_put_contents($this->activeDatabase, 'active');
        $callbackFailure = new RuntimeException('callback failed');

        try {
            $this->withoutSqliteDatabase(function () use ($callbackFailure): never {
                file_put_contents($this->baseDatabase, 'temporary base');
                file_put_contents($this->activeDatabase, 'temporary active');

                throw $callbackFailure;
            });
            $this->fail('Expected the callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackFailure, $exception);
        }

        $this->assertSame('base', file_get_contents($this->baseDatabase));
        $this->assertSame('active', file_get_contents($this->activeDatabase));
        $this->assertSame([], glob($this->temporaryDirectory . '/*.backup-*') ?: []);
    }

    #[Test]
    public function itAttemptsTheActiveRestoreAfterTheBaseRestoreFails(): void
    {
        file_put_contents($this->baseDatabase, 'base');
        file_put_contents($this->activeDatabase, 'active');
        $this->testFilesystem->failMoveFromPrefix = $this->baseDatabase . '.backup-';

        try {
            $this->withoutSqliteDatabase(function (): void {
                file_put_contents($this->baseDatabase, 'temporary base');
                file_put_contents($this->activeDatabase, 'temporary active');
            });
            $this->fail('Expected the base restore to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Unable to restore SQLite database [{$this->baseDatabase}].",
                $exception->getMessage(),
            );
        }

        $this->assertFileDoesNotExist($this->baseDatabase);
        $this->assertCount(1, glob($this->baseDatabase . '.backup-*') ?: []);
        $this->assertSame('active', file_get_contents($this->activeDatabase));
    }

    #[Test]
    public function itPurgesTheConnectionBeforeEveryFileMove(): void
    {
        file_put_contents($this->baseDatabase, 'base');
        file_put_contents($this->activeDatabase, 'active');

        $this->withoutSqliteDatabase(function (): void {
            file_put_contents($this->baseDatabase, 'temporary base');
            file_put_contents($this->activeDatabase, 'temporary active');
        });

        $baseBackup = $this->moveOperation($this->baseDatabase);
        $activeBackup = $this->moveOperation($this->activeDatabase);

        $this->assertSame([
            'purge',
            "move:{$this->baseDatabase}:{$baseBackup}",
            'purge',
            "move:{$this->activeDatabase}:{$activeBackup}",
            'purge',
            "delete:{$this->baseDatabase}",
            "move:{$baseBackup}:{$this->baseDatabase}",
            'purge',
            "delete:{$this->activeDatabase}",
            "move:{$activeBackup}:{$this->activeDatabase}",
        ], $this->testFilesystem->operations);
    }

    #[Test]
    public function itRestoresTheConfiguredDatabaseWhenTheInitialPurgeFails(): void
    {
        $callbackCalled = false;
        $this->app->make('config')->set('database.connections.sqlite.database', $this->baseDatabase);
        $this->failingPurge = 1;

        try {
            $this->useActiveSqliteDatabasePath(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            $this->fail('Expected the initial purge to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('purge 1 failed', $exception->getMessage());
        }

        $this->assertFalse($callbackCalled);
        $this->assertSame(
            $this->baseDatabase,
            $this->app->make('config')->get('database.connections.sqlite.database'),
        );
        $this->assertSame(['purge', 'purge'], $this->testFilesystem->operations);
    }

    #[Test]
    public function itRestoresTheConfiguredDatabaseWhenTheFinalPurgeFails(): void
    {
        $callbackCalled = false;
        $this->app->make('config')->set('database.connections.sqlite.database', $this->baseDatabase);
        $this->failingPurge = 2;

        try {
            $this->useActiveSqliteDatabasePath(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            $this->fail('Expected the final purge to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('purge 2 failed', $exception->getMessage());
        }

        $this->assertTrue($callbackCalled);
        $this->assertSame(
            $this->baseDatabase,
            $this->app->make('config')->get('database.connections.sqlite.database'),
        );
        $this->assertSame(['purge', 'purge'], $this->testFilesystem->operations);
    }

    #[Test]
    public function itPreservesTheCallbackFailureWhenTheFinalPurgeFails(): void
    {
        $callbackFailure = new RuntimeException('callback failed');
        $this->app->make('config')->set('database.connections.sqlite.database', $this->baseDatabase);
        $this->failingPurge = 2;

        try {
            $this->useActiveSqliteDatabasePath(static function () use ($callbackFailure): never {
                throw $callbackFailure;
            });
            $this->fail('Expected the callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackFailure, $exception);
        }

        $this->assertSame(['purge', 'purge'], $this->testFilesystem->operations);
        $this->assertSame(
            $this->baseDatabase,
            $this->app->make('config')->get('database.connections.sqlite.database'),
        );
    }

    #[Test]
    public function itFailsWhenTheBaseExampleCannotBeCopied(): void
    {
        file_put_contents($this->baseDatabase . '.example', 'example');
        $this->testFilesystem->failCopyTarget = $this->baseDatabase;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create SQLite database [{$this->baseDatabase}].");

        try {
            $this->withSqliteDatabase(static function (): void {
            });
        } finally {
            $this->assertFileDoesNotExist($this->baseDatabase);
            $this->assertFileDoesNotExist($this->activeDatabase);
            $this->assertSame('example', file_get_contents($this->baseDatabase . '.example'));
        }
    }

    #[Test]
    public function itRemovesTheCreatedBaseWhenTheActiveExampleCopyFails(): void
    {
        file_put_contents($this->baseDatabase . '.example', 'example');
        $this->testFilesystem->failCopyTarget = $this->activeDatabase;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create SQLite database [{$this->activeDatabase}].");

        try {
            $this->withSqliteDatabase(static function (): void {
            });
        } finally {
            $this->assertFileDoesNotExist($this->baseDatabase);
            $this->assertFileDoesNotExist($this->activeDatabase);
            $this->assertSame('example', file_get_contents($this->baseDatabase . '.example'));
        }
    }

    #[Test]
    public function itPreservesTheCallbackFailureWhileAttemptingEveryCreatedFileCleanup(): void
    {
        file_put_contents($this->baseDatabase . '.example', 'example');
        $callbackFailure = new RuntimeException('callback failed');
        $this->testFilesystem->failDeleteTarget = $this->baseDatabase;

        try {
            $this->withSqliteDatabase(static function () use ($callbackFailure): never {
                throw $callbackFailure;
            });
            $this->fail('Expected the callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackFailure, $exception);
        }

        $this->assertContains("delete:{$this->baseDatabase}", $this->testFilesystem->operations);
        $this->assertContains("delete:{$this->activeDatabase}", $this->testFilesystem->operations);
        $this->assertFileExists($this->baseDatabase);
        $this->assertFileDoesNotExist($this->activeDatabase);
    }

    protected function baseSqliteDatabasePath(): string
    {
        return $this->baseDatabase;
    }

    protected function activeSqliteDatabasePath(): string
    {
        return $this->activeDatabase;
    }

    protected function purgeSqliteConnection(): void
    {
        $this->testFilesystem->operations[] = 'purge';

        ++$this->purgeCalls;

        if ($this->failingPurge === $this->purgeCalls) {
            throw new RuntimeException("purge {$this->purgeCalls} failed");
        }
    }

    /**
     * Find the destination of the first move from a path.
     */
    protected function moveOperation(string $source): string
    {
        $prefix = "move:{$source}:";

        foreach ($this->testFilesystem->operations as $operation) {
            if (str_starts_with($operation, $prefix)) {
                return substr($operation, strlen($prefix));
            }
        }

        throw new RuntimeException("No move from [{$source}] was recorded.");
    }
}

class SqliteFileTestFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $operations = [];

    public ?string $failMoveFrom = null;

    public ?string $failMoveFromPrefix = null;

    public ?string $failCopyTarget = null;

    public ?string $failDeleteTarget = null;

    /**
     * Move a file to a new location.
     */
    public function move(string $path, string $target): bool
    {
        $this->operations[] = "move:{$path}:{$target}";

        if ($path === $this->failMoveFrom
            || ($this->failMoveFromPrefix !== null && str_starts_with($path, $this->failMoveFromPrefix))) {
            return false;
        }

        return parent::move($path, $target);
    }

    /**
     * Copy a file to a new location.
     */
    public function copy(string $path, string $target): bool
    {
        $this->operations[] = "copy:{$path}:{$target}";

        return $target !== $this->failCopyTarget && parent::copy($path, $target);
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        foreach ((array) $paths as $path) {
            $this->operations[] = "delete:{$path}";
        }

        if (is_string($paths) && $paths === $this->failDeleteTarget) {
            return false;
        }

        return parent::delete($paths);
    }
}
