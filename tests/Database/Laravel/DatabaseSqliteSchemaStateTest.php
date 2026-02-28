<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Schema\SqliteSchemaState;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use Symfony\Component\Process\Process;

/**
 * @internal
 * @coversNothing
 */
class DatabaseSqliteSchemaStateTest extends TestCase
{
    public function testLoadSchemaToDatabase(): void
    {
        $config = ['driver' => 'sqlite', 'database' => 'database/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true, 'name' => 'sqlite'];
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

        $this->assertSame('sqlite3 "${:LARAVEL_LOAD_DATABASE}" < "${:LARAVEL_LOAD_PATH}"', $factoryCalledWith[0]);

        $process->shouldHaveReceived('mustRun')->with(null, [
            'LARAVEL_LOAD_DATABASE' => 'database/database.sqlite',
            'LARAVEL_LOAD_PATH' => 'database/schema/sqlite-schema.dump',
        ]);
    }

    public function testLoadSchemaToInMemory(): void
    {
        $config = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true, 'name' => 'sqlite'];
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
}
