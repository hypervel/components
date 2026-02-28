<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Sqlite;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineEnvironment;

/**
 * @internal
 * @coversNothing
 */
class ConnectorTest extends SqliteTestCase
{
    /**
     * Configure custom_sqlite connection with custom pragma settings including query_only.
     */
    protected function useCustomSqliteConnection(Application $app): void
    {
        $app->make('config')->set('database.connections.custom_sqlite', [
            'driver' => 'sqlite',
            'database' => database_path('custom.sqlite'),
            'foreign_key_constraints' => true,
            'busy_timeout' => 12345,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
            'pragmas' => [
                'query_only' => true,
            ],
        ]);
    }

    /**
     * Configure writable_sqlite connection for testing dynamic pragma modification.
     */
    protected function useWritableSqliteConnection(Application $app): void
    {
        $app->make('config')->set('database.connections.writable_sqlite', [
            'driver' => 'sqlite',
            'database' => database_path('custom.sqlite'),
            'foreign_key_constraints' => true,
            'busy_timeout' => 12345,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Create the custom.sqlite database file for tests that need it
        if (file_exists(database_path('custom.sqlite'))) {
            return;
        }

        Schema::createDatabase(database_path('custom.sqlite'));
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropDatabaseIfExists(database_path('custom.sqlite'));
    }

    /**
     * Test default pragma values for SQLite connection.
     *
     * The default config has foreign_key_constraints => true, so foreign_keys is 1.
     * journal_mode differs based on database type: 'memory' for :memory:, 'delete' for file-based.
     */
    public function testDefaultPragmaValues(): void
    {
        // Default config has foreign_key_constraints => true
        $this->assertSame(1, Schema::pragma('foreign_keys'));
        $this->assertSame(60000, Schema::pragma('busy_timeout'));

        $expectedJournalMode = $this->usesSqliteInMemoryDatabaseConnection() ? 'memory' : 'delete';
        $this->assertSame($expectedJournalMode, Schema::pragma('journal_mode'));

        $this->assertSame(2, Schema::pragma('synchronous'));
    }

    /**
     * Test custom pragma configuration via connection config.
     */
    #[DefineEnvironment('useCustomSqliteConnection')]
    public function testCustomPragmaConfiguration(): void
    {
        $schema = Schema::connection('custom_sqlite');

        $this->assertSame(1, $schema->pragma('foreign_keys'));
        $this->assertSame(12345, $schema->pragma('busy_timeout'));
        $this->assertSame('wal', $schema->pragma('journal_mode'));
        $this->assertSame(1, $schema->pragma('synchronous'));
        $this->assertSame(1, $schema->pragma('query_only'));
    }

    /**
     * Test dynamic pragma modification at runtime.
     */
    #[DefineEnvironment('useWritableSqliteConnection')]
    public function testDynamicPragmaModification(): void
    {
        $schema = Schema::connection('writable_sqlite');

        // Verify initial values from config
        $this->assertSame(1, $schema->pragma('foreign_keys'));
        $this->assertSame(12345, $schema->pragma('busy_timeout'));

        // Modify pragmas dynamically
        $schema->pragma('foreign_keys', 0);
        $schema->pragma('busy_timeout', 54321);
        $schema->pragma('journal_mode', 'delete');
        $schema->pragma('synchronous', 0);

        // Verify changes
        $this->assertSame(0, $schema->pragma('foreign_keys'));
        $this->assertSame(54321, $schema->pragma('busy_timeout'));
        $this->assertSame('delete', $schema->pragma('journal_mode'));
        $this->assertSame(0, $schema->pragma('synchronous'));
    }
}
