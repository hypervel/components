<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use PDO;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;

use function Hypervel\Testbench\remote;

class SchemaStateTest extends SqliteTestCase
{
    use InteractsWithPublishedFiles;

    protected $files = [
        'database/schema/*',
    ];

    protected function tearDown(): void
    {
        remote('db:wipe')->mustRun();

        // Restore migrations table for parent's migrate:rollback
        remote('migrate:install')->mustRun();

        parent::tearDown();
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSchemaDumpOnSqlite(): void
    {
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Test cannot be run using :in-memory: database connection');
        }

        $connection = DB::connection();
        $connection->getSchemaBuilder()->createDatabase($connection->getConfig('database'));

        $connection->statement('CREATE TABLE IF NOT EXISTS migrations (id integer primary key autoincrement not null, migration varchar not null, batch integer not null);');
        $connection->statement('CREATE TABLE users (id integer primary key autoincrement not null, email varchar not null, name varchar not null);');
        $connection->statement('INSERT INTO users (email, name) VALUES ("taylor@laravel.com", "Taylor Otwell");');

        $this->assertTrue($connection->table('sqlite_sequence')->exists());

        $this->app->make('files')->ensureDirectoryExists(database_path('schema'));

        $connection->getSchemaState()->dump($connection, database_path('schema/sqlite-schema.sql'));

        $this->assertFileContains([
            'CREATE TABLE migrations',
            'CREATE TABLE users',
        ], 'database/schema/sqlite-schema.sql');
        $this->assertFileNotContains([
            'sqlite_sequence',
        ], 'database/schema/sqlite-schema.sql');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSchemaDumpWithoutMigrationDataOnSqlite(): void
    {
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Test cannot be run using :in-memory: database connection');
        }

        $connection = DB::connection();
        $connection->getSchemaBuilder()->createDatabase($connection->getConfig('database'));

        $connection->statement('CREATE TABLE IF NOT EXISTS migrations (id integer primary key autoincrement not null, migration varchar not null, batch integer not null);');
        $connection->statement('CREATE TABLE users (id integer primary key autoincrement not null, email varchar not null, name varchar not null);');
        $connection->statement('INSERT INTO migrations (migration, batch) VALUES ("2014_10_12_000000_create_users_table", 1);');

        $this->app->make('files')->ensureDirectoryExists(database_path('schema'));

        $connection->getSchemaState()
            ->withMigrationTable(null)
            ->dump($connection, database_path('schema/sqlite-schema.sql'));

        $this->assertFileContains([
            'CREATE TABLE migrations',
            'CREATE TABLE users',
        ], 'database/schema/sqlite-schema.sql');

        $this->assertFileNotContains([
            'INSERT INTO "migrations"',
        ], 'database/schema/sqlite-schema.sql');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testMigrationTableNameIsLiteralAcrossShellAndDumpPatternParsing(): void
    {
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Schema dumping requires a file-backed SQLite connection.');
        }

        $connection = DB::connection();
        $migrationTable = 'app_"migrations$tag';
        $neighborTable = 'appX"migrations$tag';

        foreach ([$migrationTable, $neighborTable] as $table) {
            $connection->getSchemaBuilder()->create($table, static function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('migration');
                $blueprint->integer('batch');
            });
            $connection->table($table)->insert(['migration' => 'example_migration', 'batch' => 1]);
        }

        $this->app->make('files')->ensureDirectoryExists(database_path('schema'));
        $path = database_path('schema/sqlite-schema.sql');
        $connection->getSchemaState()->withMigrationTable($migrationTable)->dump($connection, $path);

        $restored = new PDO('sqlite::memory:');
        $restored->exec($this->app->make('files')->get($path));

        $this->assertSame('example_migration', $restored->query('SELECT migration FROM "app_""migrations$tag"')->fetchColumn());
        $this->assertSame(0, $restored->query('SELECT count(*) FROM "appX""migrations$tag"')->fetchColumn());
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSchemaDumpWithFullTextSearchTableCanBeLoadedOnSqlite(): void
    {
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Test cannot be run using :in-memory: database connection');
        }

        $connection = DB::connection();
        $connection->getSchemaBuilder()->createDatabase($connection->getConfig('database'));

        // fts5 quotes its shadow table names with single quotes and rtree with double quotes.
        $connection->statement('CREATE VIRTUAL TABLE posts USING fts5(body);');
        $connection->statement('CREATE VIRTUAL TABLE "post\'s" USING fts5(body);');
        $connection->statement('CREATE VIRTUAL TABLE "box""es" USING rtree(id, min_x, max_x);');

        $this->app->make('files')->ensureDirectoryExists(database_path('schema'));

        $connection->getSchemaState()->dump($connection, database_path('schema/sqlite-schema.sql'));

        $this->assertFileNotContains([
            'posts_data',
            "post''s_data",
            'box""es_node',
        ], 'database/schema/sqlite-schema.sql');

        $connection->getSchemaBuilder()->dropAllTables();
        $connection->getSchemaState()->load(database_path('schema/sqlite-schema.sql'));

        $this->assertTrue($connection->getSchemaBuilder()->hasTable('posts'));
        $this->assertTrue($connection->getSchemaBuilder()->hasTable("post's"));
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('box"es'));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSchemaDumpKeepsDeclarationTextWhenLoaded(): void
    {
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Test cannot be run using :in-memory: database connection');
        }

        $connection = DB::connection();
        $connection->getSchemaBuilder()->createDatabase($connection->getConfig('database'));

        $connection->statement("CREATE TABLE posts (id integer primary key, title varchar default 'Untitled (draft)' not null)");
        $connection->statement("CREATE INDEX posts_draft_index ON posts (title) WHERE title = 'Untitled (draft)'");
        // Literal text that looks like an internal or shadow table statement belongs to the declaration.
        $connection->statement('CREATE VIRTUAL TABLE docs USING fts5(body)');
        $connection->statement(<<<'SQL'
            CREATE TABLE examples (body text default 'CREATE TABLE sqlite_bad(x);
            kept', other text default "before
            CREATE TABLE IF NOT EXISTS 'docs_data'(id INTEGER PRIMARY KEY, block BLOB);
            after")
            SQL);

        $this->app->make('files')->ensureDirectoryExists(database_path('schema'));

        $connection->getSchemaState()->withMigrationTable(null)->dump($connection, database_path('schema/sqlite-schema.sql'));
        $connection->getSchemaBuilder()->dropAllTables();
        $connection->getSchemaState()->load(database_path('schema/sqlite-schema.sql'));

        $title = array_column($connection->getSchemaBuilder()->getColumns('posts'), null, 'name')['title'];
        $examples = array_column($connection->getSchemaBuilder()->getColumns('examples'), 'default', 'name');

        $this->assertSame("'Untitled (draft)'", $title['default']);
        $this->assertSame("'CREATE TABLE sqlite_bad(x);\nkept'", $examples['body']);
        $this->assertSame("\"before\nCREATE TABLE IF NOT EXISTS 'docs_data'(id INTEGER PRIMARY KEY, block BLOB);\nafter\"", $examples['other']);
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('docs'));
        $this->assertStringContainsString(
            "WHERE title = 'Untitled (draft)'",
            $connection->scalar("select sql from sqlite_master where name = 'posts_draft_index'"),
        );
    }
}
