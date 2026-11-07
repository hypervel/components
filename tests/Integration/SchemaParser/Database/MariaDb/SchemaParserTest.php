<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\SchemaParser\Database\MariaDb;

use Hypervel\Tests\Integration\Database\MariaDb\MariaDbTestCase;
use Hypervel\Tests\Integration\SchemaParser\Fixtures\ParsesMySqlDumps;

class SchemaParserTest extends MariaDbTestCase
{
    use ParsesMySqlDumps;
}
