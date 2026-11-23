<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NestedSetSchemaTest as BaseNestedSetSchemaTest;

#[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]
class NestedSetSchemaTest extends BaseNestedSetSchemaTest
{
}
