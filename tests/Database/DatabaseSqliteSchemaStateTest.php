<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\SqliteSchemaState;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class DatabaseSqliteSchemaStateTest extends TestCase
{
    #[DataProvider('fileDatabaseProvider')]
    public function testLoadSchemaToDatabase(string $database): void
    {
        $config = ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'name' => 'sqlite'];
        $connection = m::mock(SQLiteConnection::class);
        $connection->expects('getConfig')->andReturn($config);
        $connection->expects('getDatabaseName')->andReturn($config['database']);

        $process = m::spy(Process::class);
        $command = null;
        $processFactory = function (string $givenCommand) use ($process, &$command): Process {
            $command = $givenCommand;

            return $process;
        };

        $schemaState = new SqliteSchemaState($connection, null, $processFactory);
        $schemaState->load('database/schema/sqlite-schema.dump');

        $this->assertSame('sqlite3 "${:HYPERVEL_LOAD_DATABASE}" < "${:HYPERVEL_LOAD_PATH}"', $command);

        $process->shouldHaveReceived('mustRun')->with(null, [
            'HYPERVEL_LOAD_DATABASE' => $database,
            'HYPERVEL_LOAD_PATH' => 'database/schema/sqlite-schema.dump',
        ]);
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
        $config = ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'name' => 'sqlite'];
        $connection = m::mock(SQLiteConnection::class);
        $connection->expects('getDatabaseName')->andReturn($config['database']);
        $pdo = m::spy(PDO::class);
        $connection->expects('getPdo')->andReturn($pdo);

        $files = m::mock(Filesystem::class);
        $files->expects('get')->andReturn('CREATE TABLE IF NOT EXISTS "migrations" ("id" integer not null primary key autoincrement, "migration" varchar not null, "batch" integer not null);');

        $schemaState = new SqliteSchemaState($connection, $files);
        $schemaState->load('database/schema/sqlite-schema.dump');

        $pdo->shouldHaveReceived('exec')->with('CREATE TABLE IF NOT EXISTS "migrations" ("id" integer not null primary key autoincrement, "migration" varchar not null, "batch" integer not null);');
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
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn(':memory:');

        $schemaState = new SqliteSchemaState($connection, m::mock(Filesystem::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('In-memory SQLite schema loading requires a PDO-backed connection.');

        $schemaState->load('database/schema/sqlite-schema.dump');
    }
}
