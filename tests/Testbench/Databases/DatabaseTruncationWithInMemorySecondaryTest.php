<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Override;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

class DatabaseTruncationWithInMemorySecondaryTest extends TestCase
{
    use DatabaseTruncation;

    private static string $databaseDirectory;

    protected array $connectionsToTruncate = ['secondary'];

    /**
     * Create a persistent default database with retained migration state.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = [];

        self::$databaseDirectory = ParallelTesting::tempDir('database-truncation-secondary');

        $files = new Filesystem;
        $files->ensureDirectoryExists(self::$databaseDirectory);
        $files->put(self::$databaseDirectory . '/database.sqlite', '');
    }

    /**
     * Configure persistent and in-memory connections.
     */
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'database.default' => 'testing',
            'database.connections.testing.database' => self::$databaseDirectory . '/database.sqlite',
            'database.connections.secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
    }

    /**
     * Load migrations for both connections.
     */
    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            workbench_path('database/migrations'),
            dirname(__DIR__) . '/Fixtures/database/secondary-migrations',
        ]);
    }

    #[Test]
    public function itMigratesTheMissingSecondaryBeforeTruncating(): void
    {
        $this->assertTrue(Schema::hasTable('testbench_users'));
        $this->assertTrue(Schema::connection('secondary')->hasTable('secondary_records'));
        DB::table('testbench_users')->insert(['email' => 'retained@example.com', 'password' => 'secret']);
        DB::connection('secondary')->table('secondary_records')->insert(['value' => 'truncated']);
    }

    #[Test]
    #[Depends('itMigratesTheMissingSecondaryBeforeTruncating')]
    public function itReusesTheSchemaAndTruncatesOnlyTheSelectedConnection(): void
    {
        $this->assertSame(1, DB::table('testbench_users')->where('email', 'retained@example.com')->count());
        $this->assertSame(0, DB::connection('secondary')->table('secondary_records')->count());
    }

    /**
     * Verify migration-state cleanup and remove the persistent database.
     */
    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();

            self::assertFalse(RefreshDatabaseState::$migrated);
            self::assertSame([], RefreshDatabaseState::$inMemoryConnections);
        } finally {
            (new Filesystem)->deleteDirectory(self::$databaseDirectory);
        }
    }
}
