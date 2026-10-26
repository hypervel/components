<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\MySqlConnection;
use Hypervel\Database\Query\Processors\MySqlProcessor;
use Hypervel\Database\Schema\Grammars\MySqlGrammar;
use Hypervel\Database\Schema\MySqlBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseMySQLSchemaBuilderTest extends TestCase
{
    public function testHasTable(): void
    {
        $connection = m::mock(MySqlConnection::class);
        $connection->shouldReceive('usesBackslashEscapes')->andReturnTrue();
        $grammar = new MySqlGrammar($connection);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $builder = new MySqlBuilder($connection);
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists(null, 'prefix_table'))->andReturn([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = m::mock(MySqlConnection::class);
        $connection->shouldReceive('usesBackslashEscapes')->andReturnTrue();
        $grammar = new MySqlGrammar($connection);
        $processor = new MySqlProcessor;
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $builder = new MySqlBuilder($connection);
        $connection->expects('getTablePrefix')->andReturn('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'prefix_table'))
            ->andReturn([(object) ['name' => 'column', 'type_name' => 'int', 'type' => 'int', 'collation' => null, 'nullable' => 'YES', 'default' => null, 'comment' => null, 'expression' => null, 'extra' => '']]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
