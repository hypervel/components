<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\TestCase;
use Mockery as m;

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
}
