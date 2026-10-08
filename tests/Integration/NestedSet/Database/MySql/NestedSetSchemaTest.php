<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NestedSetSchemaTest as BaseNestedSetSchemaTest;

#[RequiresDatabase('mysql', '>=8.0')]
class NestedSetSchemaTest extends BaseNestedSetSchemaTest
{
}
