<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\MySqlConnection;
use Hypervel\Database\Query\Processors\MySqlProcessor;
use Hypervel\Database\Schema\Grammars\MySqlGrammar;
use Hypervel\Database\Schema\MySqlBuilder;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class DatabaseMySQLSchemaBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testHasTable(): void
    {
        $connection = Double::for(MySqlConnection::class);
        $connection->allows('usesBackslashEscapes')->returns(true);
        $grammar = new MySqlGrammar($connection);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = new MySqlBuilder($connection);
        $connection->expects('getTablePrefix')->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists(null, 'prefix_table'))->returns([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = Double::for(MySqlConnection::class);
        $connection->allows('usesBackslashEscapes')->returns(true);
        $grammar = new MySqlGrammar($connection);
        $processor = new MySqlProcessor;
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $builder = new MySqlBuilder($connection);
        $connection->expects('getTablePrefix')->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'prefix_table'))
            ->returns([(object) ['name' => 'column', 'type_name' => 'int', 'type' => 'int', 'collation' => null, 'nullable' => 'YES', 'default' => null, 'comment' => null, 'expression' => null, 'extra' => '']]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
