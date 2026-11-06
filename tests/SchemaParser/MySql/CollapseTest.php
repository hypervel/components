<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\Tests\TestCase;

class CollapseTest extends TestCase
{
    public function testCollapsing(): void
    {
        $parser = new SqlParser;

        $this->assertSame(['a', 'b'], $parser->lex('a b'));
        $this->assertSame(['UNIQUE KEY'], $parser->lex('UNIQUE key'));
    }
}
