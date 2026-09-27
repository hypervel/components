<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\NestedSet\Database\NestedSetDatabaseTestCase;

#[RequiresDatabase('mysql', '>=8.0')]
class NestedSetDatabaseTest extends NestedSetDatabaseTestCase
{
}
