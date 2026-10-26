<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\Query\Processors\MariaDbProcessor;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseMariaDbSchemaBuilderTest extends TestCase
{
    public function testHasTable(): void
    {
        $connection = m::mock(MariaDbConnection::class);
        $connection->shouldReceive('usesBackslashEscapes')->andReturnTrue();
        $grammar = new MariaDbGrammar($connection);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $builder = new MariaDbBuilder($connection);
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists(null, 'prefix_table'))->andReturn([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = m::mock(MariaDbConnection::class);
        $connection->shouldReceive('usesBackslashEscapes')->andReturnTrue();
        $grammar = new MariaDbGrammar($connection);
        $processor = new MariaDbProcessor;
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $builder = new MariaDbBuilder($connection);
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'prefix_table'))
            ->andReturn([(object) ['name' => 'column', 'type_name' => 'int', 'type' => 'int', 'collation' => null, 'nullable' => 'YES', 'default' => null, 'comment' => null, 'expression' => null, 'extra' => '']]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
