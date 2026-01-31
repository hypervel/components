<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Processors\MariaDbProcessor;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMariaDbSchemaBuilderTest extends TestCase
{
    public function testHasTable()
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(MariaDbGrammar::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('db');
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $builder = new MariaDbBuilder($connection);
        $grammar->shouldReceive('compileTableExists')->once()->andReturn('sql');
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('prefix_');
        $connection->shouldReceive('scalar')->once()->with('sql')->andReturn(1);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testGetColumnListing()
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(MariaDbGrammar::class);
        $processor = m::mock(MariaDbProcessor::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('db');
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $connection->shouldReceive('getPostProcessor')->andReturn($processor);
        $grammar->shouldReceive('compileColumns')->with(null, 'prefix_table')->once()->andReturn('sql');
        $processor->shouldReceive('processColumns')->once()->andReturn([['name' => 'column']]);
        $builder = new MariaDbBuilder($connection);
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('prefix_');
        $connection->shouldReceive('selectFromWriteConnection')->once()->with('sql')->andReturn([['name' => 'column']]);

        $this->assertEquals(['column'], $builder->getColumnListing('table'));
    }
}
