<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Sqlite\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Integration\Database\Laravel\Sqlite\SqliteTestCase;
use Override;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\default_skeleton_path;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('database.connections.sqlite.journal_mode', 'wal')]
class MigrateFreshCommandWithJournalModeWalTest extends SqliteTestCase
{
    #[Override]
    protected function setUp(): void
    {
        $files = new Filesystem();

        $files->copy(
            join_paths(__DIR__, 'stubs', 'database-journal-mode-wal.sqlite'),
            join_paths(default_skeleton_path(), 'database', 'database.sqlite')
        );

        $this->beforeApplicationDestroyed(function () use ($files) {
            $files->delete(database_path('database.sqlite'));
        });

        parent::setUp();
    }

    public function testRunningMigrateFreshCommandWithWalJournalMode()
    {
        $this->artisan('migrate:fresh', [
            '--realpath' => true,
            '--path' => default_migration_path(),
        ])->run();

        $this->assertTrue(Schema::hasTable('users'));
    }
}
