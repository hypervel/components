<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseSQLiteBuilderTest extends TestCase
{
    public function testCreateDatabase()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        $builder = new SQLiteBuilder($connection);

        File::shouldReceive('put')
            ->once()
            ->with('my_temporary_database_a', '')
            ->andReturn(20); // bytes

        $this->assertTrue($builder->createDatabase('my_temporary_database_a'));

        File::shouldReceive('put')
            ->once()
            ->with('my_temporary_database_b', '')
            ->andReturn(false);

        $this->assertFalse($builder->createDatabase('my_temporary_database_b'));
    }

    public function testDropDatabaseIfExists()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        $builder = new SQLiteBuilder($connection);

        File::shouldReceive('exists')
            ->once()
            ->andReturn(true);

        File::shouldReceive('delete')
            ->once()
            ->with('my_temporary_database_b')
            ->andReturn(true);

        $this->assertTrue($builder->dropDatabaseIfExists('my_temporary_database_b'));

        File::shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->assertTrue($builder->dropDatabaseIfExists('my_temporary_database_c'));

        File::shouldReceive('exists')
            ->once()
            ->andReturn(true);

        File::shouldReceive('delete')
            ->once()
            ->with('my_temporary_database_c')
            ->andReturn(false);

        $this->assertFalse($builder->dropDatabaseIfExists('my_temporary_database_c'));
    }

    #[DataProvider('nonFileDatabaseNames')]
    public function testCreateDatabaseRejectsNonFileDatabaseNames(string $name): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        File::shouldReceive('put')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "SQLite database management requires a plain filesystem path; [{$name}] is not supported."
        );

        (new SQLiteBuilder($connection))->createDatabase($name);
    }

    #[DataProvider('nonFileDatabaseNames')]
    public function testDropDatabaseRejectsNonFileDatabaseNames(string $name): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        File::shouldReceive('exists')->never();
        File::shouldReceive('delete')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "SQLite database management requires a plain filesystem path; [{$name}] is not supported."
        );

        (new SQLiteBuilder($connection))->dropDatabaseIfExists($name);
    }

    public static function nonFileDatabaseNames(): array
    {
        return [
            'literal memory database' => [':memory:'],
            'memory URI database' => ['file::memory:'],
            'file URI database' => ['file:/tmp/database.sqlite?mode=rwc'],
        ];
    }

    public function testDropAllTablesRefreshesTheCanonicalAttachedDatabasePath(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldNotReceive('getDatabaseName');

        $builder = m::mock(SQLiteBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getSchemas')->once()->andReturn([
            ['name' => 'main', 'path' => '/canonical/database.sqlite'],
        ]);
        $builder->shouldReceive('getCurrentSchemaListing')->once()->andReturn(['main']);
        $builder->shouldReceive('refreshDatabaseFile')->once()->with('/canonical/database.sqlite');

        $builder->dropAllTables();
    }

    public function testRefreshDatabaseFileThrowsWhenTheFileCannotBeWritten(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        File::shouldReceive('put')
            ->once()
            ->with('/database.sqlite', '')
            ->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to refresh SQLite database file [/database.sqlite].');

        (new SQLiteBuilder($connection))->refreshDatabaseFile('/database.sqlite');
    }
}
