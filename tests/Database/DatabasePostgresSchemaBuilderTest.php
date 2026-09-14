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
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $builder = new PostgresBuilder($connection);
        $grammar->expects('compileTableExists')->times(2)->andReturn('sql');
        $connection->expects('getTablePrefix')->times(2)->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->andReturn([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
        $this->assertTrue($builder->hasTable('public.table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(PostgresGrammar::class);
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $grammar->expects('compileColumns')->with(null, 'prefix_table')->andReturn('sql');
        $processor->expects('processColumns')->andReturn([['name' => 'column']]);
        $builder = new PostgresBuilder($connection);
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'column']]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
