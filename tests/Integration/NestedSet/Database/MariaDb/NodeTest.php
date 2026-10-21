<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NodeTest as BaseNodeTest;

#[RequiresDatabase('mariadb')]
class NodeTest extends BaseNodeTest
{
}
