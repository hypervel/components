<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Sqlite\Console;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\Laravel\Sqlite\SqliteTestCase;
use Override;

/**
 * Tests that migrate:fresh works correctly with WAL journal mode.
 *
 * WAL (Write-Ahead Logging) journal mode requires a file-based database,
 * so this test is skipped when using :memory:.
 *
 * The DatabaseMigrations trait runs migrate:fresh in setUp, so this test
 * verifies that the migration succeeded with WAL mode enabled.
 *
 * @internal
 * @coversNothing
 */
class MigrateFreshCommandWithJournalModeWalTest extends SqliteTestCase
{
    #[Override]
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        // Set WAL journal mode before any database connections are established.
        // This must be in defineEnvironment(), not WithConfig attribute, because
        // RequiresDatabase processes before WithConfig and establishes the connection.
        $app->get('config')->set('database.connections.sqlite.journal_mode', 'wal');
    }

    #[Override]
    protected function setUp(): void
    {
        // WAL journal mode doesn't work with :memory: databases
        if ($this->isConfiguredForInMemoryDatabase()) {
            parent::setUp();
            $this->markTestSkipped('WAL journal mode requires a file-based database, not :memory:');
        }

        // Delete any existing database file to start fresh, then create an
        // empty file. The connector will set WAL mode via the journal_mode
        // config when the connection is established.
        $databasePath = $this->getConfiguredDatabasePath();
        $this->deleteSqliteDatabaseFile($databasePath);
        touch($databasePath);

        $this->beforeApplicationDestroyed(fn () => $this->deleteSqliteDatabaseFile());

        parent::setUp();
    }

    public function testMigrateFreshWorksWithWalJournalMode(): void
    {
        // DatabaseMigrations trait already ran migrate:fresh in setUp.
        // Verify it succeeded and WAL mode is active.
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame('wal', DB::scalar('pragma journal_mode'));
    }
}
