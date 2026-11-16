<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\Query\Processors\MariaDbProcessor;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class DatabaseMariaDbSchemaBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testHasTable(): void
    {
        $connection = Double::for(MariaDbConnection::class);
        $connection->allows('usesBackslashEscapes')->returns(true);
        $grammar = new MariaDbGrammar($connection);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = new MariaDbBuilder($connection);
        $connection->expects('getTablePrefix')->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists(null, 'prefix_table'))->returns([['exists' => 1]]);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testGetColumnListing(): void
    {
        $connection = Double::for(MariaDbConnection::class);
        $connection->allows('usesBackslashEscapes')->returns(true);
        $grammar = new MariaDbGrammar($connection);
        $processor = new MariaDbProcessor;
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $builder = new MariaDbBuilder($connection);
        $connection->expects('getTablePrefix')->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'prefix_table'))
            ->returns([(object) ['name' => 'column', 'type_name' => 'int', 'type' => 'int', 'collation' => null, 'nullable' => 'YES', 'default' => null, 'comment' => null, 'expression' => null, 'extra' => '']]);

        $this->assertSame(['column'], $builder->getColumnListing('table'));
    }
}
