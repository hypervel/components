<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite\Console;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Override;

/**
 * Tests that migrate:fresh works correctly with WAL journal mode.
 *
 * WAL (Write-Ahead Logging) journal mode requires a file-based database.
 * This test owns its file so resetting it cannot affect another parallel worker.
 *
 * The DatabaseMigrations trait runs migrate:fresh in setUp, so this test
 * verifies that the migration succeeded with WAL mode enabled.
 */
#[WithMigration]
class MigrateFreshCommandWithJournalModeWalTest extends TestCase
{
    use DatabaseMigrations;

    protected string $databaseDirectory;

    /**
     * Configure the test-owned SQLite file with WAL enabled.
     */
    #[Override]
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.default', 'sqlite');
        $app->make('config')->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->databaseDirectory . '/database.sqlite',
            'prefix' => '',
            'journal_mode' => 'wal',
        ]);
    }

    /**
     * Create a fresh database file before migrations run.
     */
    #[Override]
    protected function setUp(): void
    {
        $this->databaseDirectory = ParallelTesting::tempDir('MigrateFreshCommandWithJournalModeWalTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->databaseDirectory);
        $files->ensureDirectoryExists($this->databaseDirectory);
        touch($this->databaseDirectory . '/database.sqlite');

        parent::setUp();
    }

    /**
     * Close the application before removing its database and WAL files.
     */
    #[Override]
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->databaseDirectory);
        }
    }

    public function testMigrateFreshWorksWithWalJournalMode(): void
    {
        // DatabaseMigrations trait already ran migrate:fresh in setUp.
        // Verify it succeeded and WAL mode is active.
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame('wal', DB::scalar('pragma journal_mode'));
    }
}
