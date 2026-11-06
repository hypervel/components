<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\Tests\TestCase;

class OddsTest extends TestCase
{
    public function testAllTablesAreRead(): void
    {
        $parser = new SqlParser;

        $parser->parse('CREATE TABLE foo (id int); CREATE TABLE bar (id int);');

        $this->assertCount(2, $parser->tables);

        // REMOVED: Single-table early exit cannot produce a complete schema snapshot.
    }
}
