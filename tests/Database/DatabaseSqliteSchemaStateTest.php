<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Schema\SqliteSchemaState;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
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
        $connection->shouldReceive('getConfig')->andReturn($config);
        $connection->shouldReceive('getDatabaseName')->andReturn($config['database']);

        $process = m::spy(Process::class);
        $factoryCalledWith = null;
        $processFactory = function (...$args) use ($process, &$factoryCalledWith) {
            $factoryCalledWith = $args;
            return $process;
        };

        $schemaState = new SqliteSchemaState($connection, null, $processFactory);
        $schemaState->load('database/schema/sqlite-schema.dump');

        $this->assertSame('sqlite3 "${:HYPERVEL_LOAD_DATABASE}" < "${:HYPERVEL_LOAD_PATH}"', $factoryCalledWith[0]);

        $process->shouldHaveReceived('mustRun')->with(null, [
            'HYPERVEL_LOAD_DATABASE' => $database,
            'HYPERVEL_LOAD_PATH' => 'database/schema/sqlite-schema.dump',
        ]);
    }

    /**
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
        $connection->shouldReceive('getConfig')->andReturn($config);
        $connection->shouldReceive('getDatabaseName')->andReturn($config['database']);
        $connection->shouldReceive('getPdo')->andReturn($pdo = m::spy(PDO::class));

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('get')->andReturn('CREATE TABLE IF NOT EXISTS "migrations" ("id" integer not null primary key autoincrement, "migration" varchar not null, "batch" integer not null);');

        $schemaState = new SqliteSchemaState($connection, $files);
        $schemaState->load('database/schema/sqlite-schema.dump');

        $pdo->shouldHaveReceived('exec')->with('CREATE TABLE IF NOT EXISTS "migrations" ("id" integer not null primary key autoincrement, "migration" varchar not null, "batch" integer not null);');
    }

    /**
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
}
