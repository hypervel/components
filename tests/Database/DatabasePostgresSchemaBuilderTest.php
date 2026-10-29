<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Database\Schema\Grammars\PostgresGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabasePostgresSchemaBuilderTest extends TestCase
{
    public function testHasTable(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $builder = new PostgresBuilder($connection);
        $connection->expects('getTablePrefix')->times(2)->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists(null, 'prefix_table'))->andReturn([['exists' => 1]]);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists('public', 'prefix_table'))->andReturn([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
        $this->assertTrue($builder->hasTable('public.table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $connection->shouldReceive('getServerVersion')->andReturn('12.0.0');
        $processor = new PostgresProcessor;
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $builder = new PostgresBuilder($connection);
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'prefix_table'))
            ->andReturn([(object) ['name' => 'column', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => 'YES', 'default' => null, 'comment' => null, 'generated' => null]]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
