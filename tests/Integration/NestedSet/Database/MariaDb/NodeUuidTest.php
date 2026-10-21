<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NodeUuidTest as BaseNodeUuidTest;

#[RequiresDatabase('mariadb')]
class NodeUuidTest extends BaseNodeUuidTest
{
}
