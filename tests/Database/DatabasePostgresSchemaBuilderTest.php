<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Database\Schema\Grammars\PostgresGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class DatabasePostgresSchemaBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testHasTable(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = new PostgresBuilder($connection);
        $connection->expects('getTablePrefix')->times(2)->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists(null, 'prefix_table'))->returns([['exists' => 1]]);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists('public', 'prefix_table'))->returns([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
        $this->assertTrue($builder->hasTable('public.table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $connection->allows('getServerVersion')->returns('12.0.0');
        $processor = new PostgresProcessor;
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $builder = new PostgresBuilder($connection);
        $connection->expects('getTablePrefix')->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'prefix_table'))
            ->returns([(object) ['name' => 'column', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => 'YES', 'default' => null, 'comment' => null, 'generated' => null]]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
