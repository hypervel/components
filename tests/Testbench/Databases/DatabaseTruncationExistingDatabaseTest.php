<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

#[ResetRefreshDatabaseState]
class DatabaseTruncationExistingDatabaseTest extends TestCase
{
    use DatabaseTruncation;

    private static string $databaseDirectory;

    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        self::$databaseDirectory = ParallelTesting::tempDir('database-truncation-existing');

        $files = new Filesystem;
        $files->ensureDirectoryExists(self::$databaseDirectory);
        $files->put(self::$databaseDirectory . '/database.sqlite', '');

        $app->make('config')->set(
            'database.connections.testing.database',
            self::$databaseDirectory . '/database.sqlite',
        );
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        RefreshDatabaseState::$migrated = true;

        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    #[Test]
    public function itRunsNewMigrationPathsWhenThePersistentDatabaseWasAlreadyMigrated(): void
    {
        $this->assertCount(1, $this->cachedTestMigratorProcessors);
        $this->assertTrue(Schema::hasTable('testbench_users'));
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            parent::tearDown();

            $this->assertTrue(RefreshDatabaseState::$migrated);
        } finally {
            (new Filesystem)->deleteDirectory(self::$databaseDirectory);
        }
    }
}
