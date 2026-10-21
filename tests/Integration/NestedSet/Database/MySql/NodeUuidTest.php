<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NodeUuidTest as BaseNodeUuidTest;

#[RequiresDatabase('mysql', '>=8.0')]
class NodeUuidTest extends BaseNodeUuidTest
{
}
