<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\SchemaParser\Database\MySql;

use Hypervel\Tests\Integration\Database\MySql\MySqlTestCase;
use Hypervel\Tests\Integration\SchemaParser\Fixtures\ParsesMySqlDumps;

class SchemaParserTest extends MySqlTestCase
{
    use ParsesMySqlDumps;
}
