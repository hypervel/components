<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Processors\MariaDbProcessor;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseMariaDbSchemaBuilderTest extends TestCase
{
    public function testHasTable(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(MariaDbGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $builder = new MariaDbBuilder($connection);
        $grammar->expects('compileTableExists')->andReturn('sql');
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(MariaDbGrammar::class);
        $processor = m::mock(MariaDbProcessor::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $grammar->expects('compileColumns')->with(null, 'prefix_table')->andReturn('sql');
        $processor->expects('processColumns')->andReturn([['name' => 'column']]);
        $builder = new MariaDbBuilder($connection);
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'column']]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
