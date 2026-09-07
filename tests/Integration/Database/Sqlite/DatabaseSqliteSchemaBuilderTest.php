<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseSqliteSchemaBuilderTest extends SqliteTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.default', 'conn1');

        $config->set('database.connections.conn1', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
            $table->string('age');
            $table->enum('color', ['red', 'blue']);
        });
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::drop('users');
    }

    public function testGetTablesAndColumnListing()
    {
        $tables = Schema::getTables();

        $this->assertCount(2, $tables);
        $this->assertEquals(['migrations', 'users'], array_column($tables, 'name'));

        $columns = Schema::getColumnListing('users');

        foreach (['id', 'name', 'age', 'color'] as $column) {
            $this->assertContains($column, $columns);
        }

        Schema::create('posts', function (Blueprint $table) {
            $table->integer('id');
            $table->string('title');
        });
        $tables = Schema::getTables();
        $this->assertCount(3, $tables);
        Schema::drop('posts');
    }

    public function testGetViews()
    {
        DB::connection('conn1')->statement(<<<'SQL'
CREATE VIEW users_view
AS
SELECT name,age from users;
SQL);

        $tableView = Schema::getViews();

        $this->assertCount(1, $tableView);
        $this->assertEquals('users_view', $tableView[0]['name']);

        DB::connection('conn1')->statement(<<<'SQL'
DROP VIEW IF EXISTS users_view;
SQL);

        $this->assertEmpty(Schema::getViews());
    }

    public function testGetRawIndex()
    {
        Schema::create('table', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->rawIndex('(strftime("%Y", created_at))', 'table_raw_index');
            $table->rawIndex('id, strftime("%Y", created_at)', 'table_mixed_raw_index');
        });

        $indexes = Schema::getIndexes('table');

        $this->assertSame([], collect($indexes)->firstWhere('name', 'table_raw_index')['columns']);
        $this->assertSame([], collect($indexes)->firstWhere('name', 'table_mixed_raw_index')['columns']);
    }

    public function testSchemaStateIndexMetadataDoesNotLeakIntoThePublicIndexShape(): void
    {
        $connection = DB::connection('conn1');
        $schema = $connection->getSchemaBuilder();
        $indexSql = 'CREATE INDEX "MixedCase_Index" ON "users" (lower("name"))';
        $connection->statement($indexSql);

        $this->assertSame([
            'name' => 'mixedcase_index',
            'columns' => [],
            'type' => null,
            'unique' => false,
            'primary' => false,
            'partial' => false,
        ], collect($schema->getIndexes('users'))->firstWhere('name', 'mixedcase_index'));

        $this->assertSame([
            'name' => 'mixedcase_index',
            'physical_name' => 'MixedCase_Index',
            'columns' => [],
            'type' => null,
            'unique' => false,
            'primary' => false,
            'partial' => false,
            'sql' => $indexSql,
            'origin' => 'c',
            'reconstructible' => false,
            'collations' => null,
            'descending' => null,
        ], collect($schema->getIndexesForSchemaState('users'))->firstWhere('name', 'mixedcase_index'));
    }

    public function testDropAllTablesUsesCatalogCleanupForAFileUriInWalMode(): void
    {
        $directory = ParallelTesting::tempDir('DatabaseSqliteSchemaBuilderTest-wal');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $path = $directory . '/database.sqlite';
        $files->put($path, '');
        $uri = 'file:' . $path . '?mode=rwc';
        $pdo = new PDO('sqlite:' . $uri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection = new SQLiteConnection($pdo, $uri);
        $secondPdo = new PDO('sqlite:' . $uri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $this->assertSame('wal', $pdo->query('pragma journal_mode = wal')->fetchColumn());
            $connection->statement('create table records (id integer primary key)');
            $connection->statement('insert into records values (1)');
            $connection->statement('create view record_view as select id from records');
            $this->assertSame(1, $secondPdo->query('select count(*) from records')->fetchColumn());

            $inode = fileinode($path);
            $this->assertIsInt($inode);

            try {
                $connection->getSchemaBuilder()->refreshDatabaseFile();
                $this->fail('Expected WAL database refresh to be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'SQLite database files cannot be refreshed through a connection using WAL journal mode. Use dropAllTables() to empty a database while connections are using it.',
                    $exception->getMessage()
                );
            }

            $this->assertSame(1, $pdo->query('select count(*) from records')->fetchColumn());
            $this->assertSame(1, $secondPdo->query('select count(*) from records')->fetchColumn());

            $connection->getSchemaBuilder()->dropAllTables();

            $this->assertSame([], $connection->getSchemaBuilder()->getTables());
            $this->assertSame(['record_view'], array_column($connection->getSchemaBuilder()->getViews(), 'name'));
            $this->assertSame($pdo, $connection->getPdo());
            $this->assertSame('wal', $pdo->query('pragma journal_mode')->fetchColumn());
            $this->assertSame($inode, fileinode($path));

            $freshPdo = new PDO('sqlite:' . $uri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $this->assertMissingTable($pdo, 'select * from record_view', 'records');
            $this->assertMissingTable($secondPdo, 'select * from record_view', 'records');
            $this->assertMissingTable($freshPdo, 'select * from record_view', 'records');

            $connection->statement('create table records (id integer primary key)');
            $connection->statement('insert into records values (2)');

            $this->assertSame(1, $pdo->query('select count(*) from record_view')->fetchColumn());
            $this->assertSame(1, $secondPdo->query('select count(*) from record_view')->fetchColumn());
            $this->assertSame(1, $freshPdo->query('select count(*) from record_view')->fetchColumn());
        } finally {
            $connection->disconnect();
            unset($freshPdo, $secondPdo, $pdo);
            $files->deleteDirectory($directory);
        }
    }

    public function testDropAllTablesPreservesViewsAndWritableModeInMemory(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection = new SQLiteConnection($pdo, ':memory:');
        $schema = $connection->getSchemaBuilder();

        try {
            $connection->statement('create table records (id integer primary key)');
            $connection->statement('insert into records values (1)');
            $connection->statement('create view record_view as select id from records');
            $connection->statement('pragma writable_schema = 1');

            $schema->dropAllTables();

            $this->assertSame([], $schema->getTables());
            $this->assertSame(['record_view'], array_column($schema->getViews(), 'name'));
            $this->assertSame(1, $schema->pragma('writable_schema'));
            $this->assertMissingTable($pdo, 'select * from record_view', 'records');

            $connection->statement('create table records (id integer primary key)');
            $connection->statement('insert into records values (2)');
            $this->assertSame(1, $pdo->query('select count(*) from record_view')->fetchColumn());

            $schema->dropAllViews();

            $this->assertSame([], $schema->getViews());
            $this->assertSame(1, $schema->pragma('writable_schema'));
        } finally {
            $connection->statement('pragma writable_schema = 0');
            $connection->disconnect();
        }
    }

    #[DataProvider('nonWalJournalModes')]
    public function testRefreshDatabaseFileSupportsEveryNonWalJournalMode(string $journalMode): void
    {
        $directory = ParallelTesting::tempDir("DatabaseSqliteSchemaBuilderTest-{$journalMode}");
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $path = $directory . '/database.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection = new SQLiteConnection($pdo, $path);
        $secondPdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $this->assertSame($journalMode, $pdo->query("pragma journal_mode = {$journalMode}")->fetchColumn());
            $connection->statement('create table records (id integer primary key)');
            $connection->statement('insert into records values (1)');
            $this->assertSame(1, $secondPdo->query('select count(*) from records')->fetchColumn());

            $connection->getSchemaBuilder()->refreshDatabaseFile();

            $freshPdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->assertMissingTable($pdo, 'select * from records', 'records');
            $this->assertMissingTable($secondPdo, 'select * from records', 'records');
            $this->assertMissingTable($freshPdo, 'select * from records', 'records');
        } finally {
            $connection->disconnect();
            unset($freshPdo, $secondPdo, $pdo);
            $files->deleteDirectory($directory);
        }
    }

    /**
     * Provide every non-WAL SQLite journal mode.
     */
    public static function nonWalJournalModes(): array
    {
        return [
            'delete' => ['delete'],
            'truncate' => ['truncate'],
            'persist' => ['persist'],
            'memory' => ['memory'],
            'off' => ['off'],
        ];
    }

    public function testRefreshDatabaseFileUsesTheCanonicalPathForAFileUri(): void
    {
        $directory = ParallelTesting::tempDir('DatabaseSqliteSchemaBuilderTest-uri');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $path = $directory . '/database.sqlite';
        $uri = 'file:' . $path . '?mode=rwc';
        $pdo = new PDO('sqlite:' . $uri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection = new SQLiteConnection($pdo, $uri);

        try {
            $connection->statement('create table records (id integer primary key)');

            $connection->getSchemaBuilder()->refreshDatabaseFile();

            $this->assertSame(0, filesize($path));
            $this->assertMissingTable($pdo, 'select * from records', 'records');
        } finally {
            $connection->disconnect();
            unset($pdo);
            $files->deleteDirectory($directory);
        }
    }

    public function testRefreshDatabaseFileUsesTheCanonicalPathForARelativeDatabase(): void
    {
        $directory = ParallelTesting::tempDir('DatabaseSqliteSchemaBuilderTest-relative');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $workingDirectory = getcwd();
        $this->assertIsString($workingDirectory);
        chdir($directory);
        $path = $directory . '/database.sqlite';
        $pdo = null;
        $connection = null;

        try {
            $pdo = new PDO('sqlite:database.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $connection = new SQLiteConnection($pdo, 'database.sqlite');
            $connection->statement('create table records (id integer primary key)');

            $connection->getSchemaBuilder()->refreshDatabaseFile();

            $this->assertSame(0, filesize($path));
            $this->assertMissingTable($pdo, 'select * from records', 'records');
        } finally {
            $connection?->disconnect();
            unset($pdo);
            chdir($workingDirectory);
            $files->deleteDirectory($directory);
        }
    }

    public function testRefreshDatabaseFileAcceptsAnExplicitOfflinePath(): void
    {
        $directory = ParallelTesting::tempDir('DatabaseSqliteSchemaBuilderTest-explicit');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $path = $directory . '/database.sqlite';
        $targetPdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $targetPdo->exec('create table records (id integer primary key)');
        unset($targetPdo);
        $connection = new SQLiteConnection(
            new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
            ':memory:'
        );

        try {
            $connection->getSchemaBuilder()->refreshDatabaseFile($path);

            $this->assertSame(0, filesize($path));
            $freshPdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->assertMissingTable($freshPdo, 'select * from records', 'records');
        } finally {
            $connection->disconnect();
            unset($freshPdo);
            $files->deleteDirectory($directory);
        }
    }

    public function testRefreshDatabaseFileDoesNotCreateAFileForAnInMemoryDatabase(): void
    {
        $directory = ParallelTesting::tempDir('DatabaseSqliteSchemaBuilderTest-memory');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $workingDirectory = getcwd();
        $this->assertIsString($workingDirectory);
        chdir($directory);
        $connection = null;

        try {
            $connection = new SQLiteConnection(
                new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
                ':memory:'
            );

            try {
                $connection->getSchemaBuilder()->refreshDatabaseFile();
                $this->fail('Expected an in-memory database refresh to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'SQLite database management requires a plain filesystem path; [:memory:] is not supported.',
                    $exception->getMessage()
                );
            }

            $this->assertFileDoesNotExist($directory . '/:memory:');
        } finally {
            $connection?->disconnect();
            chdir($workingDirectory);
            $files->deleteDirectory($directory);
        }
    }

    /**
     * Assert that a query fails because its table is missing.
     */
    protected function assertMissingTable(PDO $pdo, string $query, string $table): void
    {
        try {
            $pdo->query($query);
            $this->fail("Expected SQLite table [{$table}] to be missing.");
        } catch (PDOException $exception) {
            $this->assertStringContainsString('no such table:', $exception->getMessage());
            $this->assertStringContainsString($table, $exception->getMessage());
        }
    }
}
