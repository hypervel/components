<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\SqliteSchemaState;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class DatabaseSqliteSchemaStateTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('fileDatabaseProvider')]
    public function testLoadSchemaToDatabase(string $database): void
    {
        $config = ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'name' => 'sqlite'];
        $connection = Double::for(SQLiteConnection::class)->strict();
        $connection->expects('getConfig')->returns($config);
        $connection->expects('getDatabaseName')->returns($config['database']);

        $process = Double::for(Process::class)->strict();
        $process->allows('stop')->with(0.0)->returns(0); // Symfony stops the process in its destructor.
        $process->expects('mustRun')->with(null, [
            'HYPERVEL_LOAD_DATABASE' => $database,
            'HYPERVEL_LOAD_PATH' => 'database/schema/sqlite-schema.dump',
        ]);
        $command = null;
        $processFactory = function (string $givenCommand) use ($process, &$command): Process {
            $command = $givenCommand;

            return $process;
        };

        $schemaState = new SqliteSchemaState($connection, null, $processFactory);
        $schemaState->load('database/schema/sqlite-schema.dump');

        $this->assertSame('sqlite3 "${:HYPERVEL_LOAD_DATABASE}" < "${:HYPERVEL_LOAD_PATH}"', $command);
    }

    /**
     * Provide file-backed database names.
     *
     * @return array<string, array{string}>
     */
    public static function fileDatabaseProvider(): array
    {
        return [
            'plain path' => ['database/database.sqlite'],
            'file URI' => ['file:/tmp/database.sqlite?mode=rw'],
        ];
    }

    #[DataProvider('inMemoryDatabaseProvider')]
    public function testLoadSchemaToInMemory(string $database): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS "migrations" ("id" integer not null primary key autoincrement, "migration" varchar not null, "batch" integer not null);';
        $connection = Double::for(SQLiteConnection::class)->strict();
        $connection->expects('getDatabaseName')->returns($database);
        $pdo = Double::for(PDO::class)->strict();
        $pdo->expects('exec')->with($sql)->returns(0);
        $connection->expects('getPdo')->returns($pdo);

        $files = Double::for(Filesystem::class)->strict();
        $files->expects('get')->with('database/schema/sqlite-schema.dump')->returns($sql);

        $schemaState = new SqliteSchemaState($connection, $files);
        $schemaState->load('database/schema/sqlite-schema.dump');
    }

    /**
     * Provide in-memory database names.
     *
     * @return array<string, array{string}>
     */
    public static function inMemoryDatabaseProvider(): array
    {
        return [
            'literal memory' => [':memory:'],
            'memory URI path' => ['file::memory:'],
            'named memory URI' => ['file:database?mode=memory'],
        ];
    }

    public function testLoadSchemaToInMemoryRequiresPdoConnection(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getDatabaseName')->returns(':memory:');

        $schemaState = new SqliteSchemaState($connection, Double::for(Filesystem::class)->strict());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('In-memory SQLite schema loading requires a PDO-backed connection.');

        $schemaState->load('database/schema/sqlite-schema.dump');
    }

    public function testDumpRemovesShadowTables(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['database' => ':memory:']);
        $connection->statement('create virtual table posts using fts5(body)');
        $connection->statement('create table "logs_data" ("id" integer primary key autoincrement)');
        $connection->statement(<<<'SQL'
            create table examples (body text default 'CREATE TABLE sqlite_bad(x);
            kept', other text default "before
            CREATE TABLE IF NOT EXISTS 'posts_data'(id INTEGER PRIMARY KEY, block BLOB);
            after")
            SQL);
        $connection->statement('create index examples_body_index on examples (body)');
        $connection->statement('create virtual table temp.logs using fts5(message)');

        $files = Double::for(Filesystem::class)->strict();

        // Literals that look like removed statements are kept, and a quoted table name gets IF NOT EXISTS, as in .schema.
        $files->expects('put')->with('schema.sql', <<<'SQL'
            CREATE VIRTUAL TABLE posts using fts5(body);
            CREATE TABLE IF NOT EXISTS "logs_data" ("id" integer primary key autoincrement);
            CREATE TABLE examples (body text default 'CREATE TABLE sqlite_bad(x);
            kept', other text default "before
            CREATE TABLE IF NOT EXISTS 'posts_data'(id INTEGER PRIMARY KEY, block BLOB);
            after");
            CREATE INDEX examples_body_index on examples (body);

            SQL . PHP_EOL);

        (new SqliteSchemaState($connection, $files))
            ->withMigrationTable(null)
            ->dump($connection, 'schema.sql');
    }
}
