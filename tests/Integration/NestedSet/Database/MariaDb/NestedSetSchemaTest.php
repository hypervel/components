<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NestedSetSchemaTest as BaseNestedSetSchemaTest;

#[RequiresDatabase('mariadb')]
class NestedSetSchemaTest extends BaseNestedSetSchemaTest
{
}
